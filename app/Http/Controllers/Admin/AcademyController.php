<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AcademyController extends Controller
{
    public function index(): View
    {
        return view('admin.academy.index', [
            'courses' => Course::withCount(['sections', 'enrollments'])->with('category')->latest()->paginate(20),
            'categories' => CourseCategory::orderBy('sort_order')->get(),
            'partners' => User::where('role', 'partner')->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.academy.form', [
            'course' => new Course,
            'categories' => CourseCategory::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateCourse($request);
        $course = Course::create([
            ...$data,
            'created_by' => $request->attributes->get('currentUser')->id,
            'slug' => $this->uniqueSlug($data['title']),
            'published_at' => $data['status'] === 'published' ? now() : null,
        ]);
        $this->audit($request, 'course.created', $course);

        if ($course->auto_enroll) {
            $this->assignToAll($course, $request->attributes->get('currentUser')->id);
        }

        return redirect()->route('admin.academy.edit', $course)->with('success', 'Kelas berhasil dibuat. Tambahkan bab dan materi.');
    }

    public function edit(Course $course): View
    {
        return view('admin.academy.form', [
            'course' => $course->load('sections.lessons'),
            'categories' => CourseCategory::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Course $course): RedirectResponse
    {
        $data = $this->validateCourse($request);
        $course->update([
            ...$data,
            'published_at' => $data['status'] === 'published' ? ($course->published_at ?: now()) : null,
        ]);
        if ($course->auto_enroll) {
            $this->assignToAll($course, $request->attributes->get('currentUser')->id);
        }
        $this->audit($request, 'course.updated', $course);

        return back()->with('success', 'Kelas diperbarui.');
    }

    public function destroy(Request $request, Course $course): RedirectResponse
    {
        $this->audit($request, 'course.archived', $course);
        $course->delete();
        return redirect()->route('admin.academy.index')->with('success', 'Kelas diarsipkan.');
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        CourseCategory::firstOrCreate(
            ['slug' => Str::slug($data['name'])],
            ['name' => $data['name']]
        );
        return back()->with('success', 'Kategori tersedia.');
    }

    public function storeSection(Request $request, Course $course): RedirectResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:180']]);
        $course->sections()->create([
            'title' => $data['title'],
            'sort_order' => ((int) $course->sections()->max('sort_order')) + 1,
        ]);
        return back()->with('success', 'Bab ditambahkan.');
    }

    public function storeLesson(Request $request, CourseSection $section): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'type' => ['required', 'in:text,video,pdf,link,assignment,quiz'],
            'content' => ['nullable', 'string'],
            'resource_url' => ['nullable', 'url', 'max:2048'],
            'material_file' => ['nullable', 'file', 'mimes:pdf', 'max:25600'],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);
        $file = $request->file('material_file');
        unset($data['material_file']);
        $section->lessons()->create([
            ...$data,
            'file_path' => $file?->store('academy/materials', 'public'),
            'original_filename' => $file?->getClientOriginalName(),
            'sort_order' => ((int) $section->lessons()->max('sort_order')) + 1,
        ]);
        return back()->with('success', 'Materi ditambahkan.');
    }

    public function destroyLesson(Lesson $lesson): RedirectResponse
    {
        if ($lesson->file_path) {
            Storage::disk('public')->delete($lesson->file_path);
        }
        $lesson->delete();
        return back()->with('success', 'Materi dihapus.');
    }

    public function assign(Request $request, Course $course): RedirectResponse
    {
        $data = $request->validate([
            'assignment_scope' => ['required', 'in:all,selected'],
            'partner_ids' => ['nullable', 'array'],
            'partner_ids.*' => ['integer', 'exists:users,id'],
        ]);
        $adminId = $request->attributes->get('currentUser')->id;
        $partnerIds = $data['assignment_scope'] === 'all'
            ? User::where('role', 'partner')->where('is_active', true)->pluck('id')
            : collect($data['partner_ids'] ?? []);

        foreach ($partnerIds as $partnerId) {
            CourseEnrollment::firstOrCreate(
                ['course_id' => $course->id, 'user_id' => $partnerId],
                ['assigned_by' => $adminId]
            );
        }
        $this->audit($request, 'course.assigned', $course, ['participants' => $partnerIds->count()]);
        return back()->with('success', $partnerIds->count().' mitra terdaftar pada kelas.');
    }

    public function report(): View
    {
        return view('admin.academy.report', [
            'enrollments' => CourseEnrollment::with(['course', 'user'])->latest()->paginate(30),
            'summary' => [
                'participants' => CourseEnrollment::distinct('user_id')->count('user_id'),
                'enrollments' => CourseEnrollment::count(),
                'completed' => CourseEnrollment::where('status', 'completed')->count(),
                'average' => (int) round((float) CourseEnrollment::avg('progress_percent')),
            ],
        ]);
    }

    private function validateCourse(Request $request): array
    {
        return $request->validate([
            'category_id' => ['nullable', 'exists:course_categories,id'],
            'title' => ['required', 'string', 'max:180'],
            'summary' => ['required', 'string', 'max:1000'],
            'description' => ['nullable', 'string'],
            'level' => ['required', 'in:dasar,menengah,lanjutan'],
            'status' => ['required', 'in:draft,published,hidden,archived'],
            'is_mandatory' => ['nullable', 'boolean'],
            'auto_enroll' => ['nullable', 'boolean'],
            'passing_score' => ['required', 'integer', 'min:0', 'max:100'],
            'estimated_minutes' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'kelas';
        $slug = $base;
        $counter = 2;
        while (Course::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$counter++;
        }
        return $slug;
    }

    private function assignToAll(Course $course, int $adminId): void
    {
        User::where('role', 'partner')->where('is_active', true)->pluck('id')->each(
            fn (int $id) => CourseEnrollment::firstOrCreate(
                ['course_id' => $course->id, 'user_id' => $id],
                ['assigned_by' => $adminId]
            )
        );
    }

    private function audit(Request $request, string $action, Course $course, array $metadata = []): void
    {
        AuditLog::create([
            'user_id' => $request->attributes->get('currentUser')->id,
            'action' => $action,
            'subject_type' => Course::class,
            'subject_id' => $course->id,
            'metadata' => $metadata,
            'ip_address' => $request->ip(),
        ]);
    }
}

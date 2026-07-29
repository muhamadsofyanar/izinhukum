<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Support\VideoEmbed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LearningController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->attributes->get('currentUser');
        return view('partner.learning.index', [
            'enrollments' => CourseEnrollment::with('course.category')
                ->where('user_id', $user->id)->latest()->paginate(18),
        ]);
    }

    public function show(Request $request, Course $course): View
    {
        $enrollment = CourseEnrollment::where('course_id', $course->id)
            ->where('user_id', $request->attributes->get('currentUser')->id)
            ->firstOrFail();
        $course->load('sections.lessons');
        $completed = DB::table('lesson_progress')->where('enrollment_id', $enrollment->id)->pluck('lesson_id');
        $videoEmbeds = $course->sections
            ->flatMap->lessons
            ->mapWithKeys(fn (Lesson $lesson): array => [
                $lesson->id => $lesson->type === 'video'
                    ? VideoEmbed::url($lesson->resource_url)
                    : null,
            ]);

        return view('partner.learning.show', compact('course', 'enrollment', 'completed', 'videoEmbeds'));
    }

    public function complete(Request $request, Course $course, Lesson $lesson): RedirectResponse
    {
        abort_unless($lesson->section()->where('course_id', $course->id)->exists(), 404);
        $enrollment = CourseEnrollment::where('course_id', $course->id)
            ->where('user_id', $request->attributes->get('currentUser')->id)->firstOrFail();

        DB::table('lesson_progress')->insertOrIgnore([
            'enrollment_id' => $enrollment->id,
            'lesson_id' => $lesson->id,
            'completed_at' => now(),
        ]);

        $total = $course->sections()->withCount('lessons')->get()->sum('lessons_count');
        $done = DB::table('lesson_progress')->where('enrollment_id', $enrollment->id)->count();
        $progress = $total > 0 ? min(100, (int) round(($done / $total) * 100)) : 100;
        $completed = $progress === 100;
        $enrollment->update([
            'status' => $completed ? 'completed' : 'in_progress',
            'progress_percent' => $progress,
            'started_at' => $enrollment->started_at ?: now(),
            'completed_at' => $completed ? now() : null,
            'certificate_number' => $completed
                ? ($enrollment->certificate_number ?: 'CERT-IH-'.now()->format('Ym').'-'.Str::upper(Str::random(8)))
                : null,
        ]);

        return back()->with('success', $completed ? 'Kelas selesai. Sertifikat Anda sudah tersedia.' : 'Materi ditandai selesai.');
    }
}

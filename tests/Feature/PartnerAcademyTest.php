<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PartnerAcademyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_an_unlimited_course(): void
    {
        $admin = User::create([
            'role' => 'admin',
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'password-aman',
            'is_active' => true,
        ]);

        $response = $this->withSession(['portal_user_id' => $admin->id])
            ->post(route('admin.academy.store'), [
                'title' => 'Onboarding Mitra',
                'summary' => 'Materi wajib bagi seluruh mitra baru.',
                'level' => 'dasar',
                'status' => 'published',
                'passing_score' => 70,
                'estimated_minutes' => 60,
                'is_mandatory' => 1,
                'auto_enroll' => 0,
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('courses', ['title' => 'Onboarding Mitra', 'status' => 'published']);
    }

    public function test_partner_can_complete_a_lesson_and_receive_certificate(): void
    {
        $partner = User::create([
            'role' => 'partner',
            'partner_code' => 'LEG-TEST',
            'name' => 'Mitra',
            'email' => 'mitra@example.test',
            'password' => 'password-aman',
            'is_active' => true,
        ]);
        $course = Course::create([
            'title' => 'Dasar Legalitas',
            'slug' => 'dasar-legalitas',
            'summary' => 'Dasar layanan legalitas.',
            'level' => 'dasar',
            'status' => 'published',
            'passing_score' => 70,
        ]);
        $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Pendahuluan']);
        $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Mulai', 'type' => 'text']);
        $enrollment = CourseEnrollment::create(['course_id' => $course->id, 'user_id' => $partner->id]);

        $response = $this->withSession(['portal_user_id' => $partner->id])
            ->post(route('partner.learning.complete', [$course, $lesson]));

        $response->assertRedirect();
        $enrollment->refresh();
        $this->assertSame('completed', $enrollment->status);
        $this->assertSame(100, $enrollment->progress_percent);
        $this->assertNotNull($enrollment->certificate_number);

        $this->withSession(['portal_user_id' => $partner->id])
            ->get(route('partner.learning.certificate', $enrollment))
            ->assertOk()
            ->assertSee('Sertifikat Kelulusan')
            ->assertSee($enrollment->certificate_number);
    }

    public function test_admin_can_edit_a_section_and_replace_lesson_material(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('academy/materials/versi-lama.pdf', '%PDF-lama');

        $admin = User::create([
            'role' => 'admin',
            'name' => 'Admin LMS',
            'email' => 'admin-lms@example.test',
            'password' => 'password-aman',
            'is_active' => true,
        ]);
        $course = Course::create([
            'title' => 'Kelas Dapat Diedit',
            'slug' => 'kelas-dapat-diedit',
            'summary' => 'Materi pengujian edit.',
            'level' => 'dasar',
            'status' => 'draft',
            'passing_score' => 70,
        ]);
        $section = CourseSection::create([
            'course_id' => $course->id,
            'title' => 'Judul Lama',
        ]);
        $lesson = Lesson::create([
            'course_section_id' => $section->id,
            'title' => 'Materi Lama',
            'type' => 'pdf',
            'content' => 'Isi lama',
            'file_path' => 'academy/materials/versi-lama.pdf',
            'original_filename' => 'versi-lama.pdf',
        ]);

        $this->withSession(['portal_user_id' => $admin->id])
            ->put(route('admin.academy.sections.update', $section), [
                'title' => 'Judul Baru',
            ])
            ->assertRedirect();

        $this->withSession(['portal_user_id' => $admin->id])
            ->put(route('admin.academy.lessons.update', $lesson), [
                'title' => 'Materi Baru',
                'type' => 'pdf',
                'content' => 'Isi baru',
                'duration_minutes' => 12,
                'material_file' => UploadedFile::fake()->create('versi-baru.pdf', 100, 'application/pdf'),
            ])
            ->assertRedirect();

        $section->refresh();
        $lesson->refresh();
        $this->assertSame('Judul Baru', $section->title);
        $this->assertSame('Materi Baru', $lesson->title);
        $this->assertSame('Isi baru', $lesson->content);
        $this->assertSame(12, $lesson->duration_minutes);
        $this->assertSame('versi-baru.pdf', $lesson->original_filename);
        Storage::disk('local')->assertMissing('academy/materials/versi-lama.pdf');
        Storage::disk('local')->assertExists($lesson->file_path);
    }

    public function test_youtube_material_is_embedded_and_pdf_is_private(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('academy/materials/modul.pdf', '%PDF-test');

        $partner = $this->partner('LEG-LMS-1', 'lms1@example.test');
        $outsider = $this->partner('LEG-LMS-2', 'lms2@example.test');
        $course = Course::create([
            'title' => 'Kelas Video',
            'slug' => 'kelas-video',
            'summary' => 'Materi video dan PDF.',
            'level' => 'dasar',
            'status' => 'published',
            'passing_score' => 70,
        ]);
        $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Materi']);
        $video = Lesson::create([
            'course_section_id' => $section->id,
            'title' => 'Video internal',
            'type' => 'video',
            'resource_url' => 'https://youtu.be/dQw4w9WgXcQ',
        ]);
        $pdf = Lesson::create([
            'course_section_id' => $section->id,
            'title' => 'Modul privat',
            'type' => 'pdf',
            'file_path' => 'academy/materials/modul.pdf',
            'original_filename' => 'modul.pdf',
        ]);
        CourseEnrollment::create(['course_id' => $course->id, 'user_id' => $partner->id]);

        $this->withSession(['portal_user_id' => $partner->id])
            ->get(route('partner.learning.show', $course))
            ->assertOk()
            ->assertSee('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', false)
            ->assertDontSee('https://youtu.be/dQw4w9WgXcQ', false);

        $this->withSession(['portal_user_id' => $partner->id])
            ->get(route('partner.learning.material', [$course, $pdf]))
            ->assertOk();

        $this->withSession(['portal_user_id' => $outsider->id])
            ->get(route('partner.learning.material', [$course, $pdf]))
            ->assertNotFound();
    }

    public function test_archived_course_does_not_break_the_partner_course_list(): void
    {
        $partner = $this->partner('LEG-ARCHIVE', 'archive@example.test');
        $course = Course::create([
            'title' => 'Kelas Lama',
            'slug' => 'kelas-lama',
            'summary' => 'Kelas yang telah diarsipkan.',
            'level' => 'dasar',
            'status' => 'archived',
            'passing_score' => 70,
        ]);
        CourseEnrollment::create(['course_id' => $course->id, 'user_id' => $partner->id]);
        $course->delete();

        $this->withSession(['portal_user_id' => $partner->id])
            ->get(route('partner.learning.index'))
            ->assertOk()
            ->assertSee('Kelas Lama')
            ->assertSee('Kelas diarsipkan');
    }

    public function test_reader_displays_only_the_selected_lesson_content(): void
    {
        $partner = $this->partner('LEG-READER', 'reader@example.test');
        $course = Course::create([
            'title' => 'Kelas Terfokus',
            'slug' => 'kelas-terfokus',
            'summary' => 'Kelas dengan pembaca satu materi.',
            'level' => 'dasar',
            'status' => 'published',
            'passing_score' => 70,
        ]);
        $section = CourseSection::create([
            'course_id' => $course->id,
            'title' => 'Modul Utama',
        ]);
        $first = Lesson::create([
            'course_section_id' => $section->id,
            'title' => 'Materi Pertama',
            'type' => 'text',
            'content' => 'Isi unik materi pertama tidak boleh tampil.',
            'sort_order' => 1,
        ]);
        $second = Lesson::create([
            'course_section_id' => $section->id,
            'title' => 'Materi Kedua',
            'type' => 'text',
            'content' => 'Isi unik materi kedua tampil di panel utama.',
            'sort_order' => 2,
        ]);
        CourseEnrollment::create([
            'course_id' => $course->id,
            'user_id' => $partner->id,
        ]);

        $this->withSession(['portal_user_id' => $partner->id])
            ->get(route('partner.learning.show', [
                'course' => $course,
                'materi' => $second->id,
            ]))
            ->assertOk()
            ->assertSee('learning-reader', false)
            ->assertSee('Isi unik materi kedua tampil di panel utama.')
            ->assertDontSee('Isi unik materi pertama tidak boleh tampil.');

        $this->withSession(['portal_user_id' => $partner->id])
            ->get(route('partner.learning.show', [
                'course' => $course,
                'materi' => $first->id + $second->id + 999,
            ]))
            ->assertNotFound();
    }

    private function partner(string $code, string $email): User
    {
        return User::create([
            'role' => 'partner',
            'partner_code' => $code,
            'name' => 'Mitra LMS',
            'email' => $email,
            'password' => 'password-aman',
            'is_active' => true,
        ]);
    }
}

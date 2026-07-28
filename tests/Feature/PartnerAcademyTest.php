<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    }
}

<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LearningMaterialController extends Controller
{
    public function __invoke(Request $request, Course $course, Lesson $lesson): StreamedResponse
    {
        abort_unless(
            $lesson->section()->where('course_id', $course->id)->exists(),
            404,
        );

        abort_unless(
            CourseEnrollment::query()
                ->where('course_id', $course->id)
                ->where('user_id', $request->attributes->get('currentUser')->id)
                ->exists(),
            404,
        );

        $disk = Storage::disk('local')->exists((string) $lesson->file_path)
            ? 'local'
            : 'public';

        abort_unless(
            $lesson->file_path && Storage::disk($disk)->exists($lesson->file_path),
            404,
        );

        return Storage::disk($disk)->response(
            $lesson->file_path,
            $lesson->original_filename ?: 'materi.pdf',
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.addslashes($lesson->original_filename ?: 'materi.pdf').'"',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
        );
    }
}

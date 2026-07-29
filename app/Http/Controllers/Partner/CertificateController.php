<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\CourseEnrollment;
use App\Services\BrandingService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CertificateController extends Controller
{
    public function __invoke(
        Request $request,
        CourseEnrollment $enrollment,
        BrandingService $brandingService,
    ): View {
        $user = $request->attributes->get('currentUser');

        abort_unless($enrollment->user_id === $user->id, 404);
        abort_unless(
            $enrollment->status === 'completed'
                && $enrollment->completed_at
                && $enrollment->certificate_number,
            404,
        );

        $enrollment->load(['course', 'user']);

        return view('partner.learning.certificate', [
            'enrollment' => $enrollment,
            'branding' => $brandingService->document(),
        ]);
    }
}

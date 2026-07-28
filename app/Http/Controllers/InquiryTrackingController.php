<?php

namespace App\Http\Controllers;

use App\Models\Inquiry;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InquiryTrackingController extends Controller
{
    public function index(Request $request): View
    {
        $inquiry = null;
        $reference = mb_strtoupper(trim((string) $request->query('reference', '')));
        $phone = trim((string) $request->query('phone', ''));

        if ($reference !== '' && $phone !== '') {
            $inquiry = Inquiry::query()
                ->with('package.service')
                ->where('reference', $reference)
                ->where('phone', $phone)
                ->first();
        }

        return view('tracking', compact('inquiry', 'reference', 'phone'));
    }
}

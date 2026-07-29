<?php

namespace App\Http\Controllers;

use App\Models\Inquiry;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InquiryTrackingController extends Controller
{
    public function index(): View
    {
        return view('tracking', [
            'inquiry' => null,
            'reference' => '',
            'searched' => false,
        ]);
    }

    public function search(Request $request): View
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:40'],
            'phone' => ['required', 'string', 'max:32'],
        ]);

        $reference = mb_strtoupper(trim($data['reference']));
        $phone = trim($data['phone']);
        $inquiry = Inquiry::query()
            ->with(['package.service', 'serviceOrder'])
            ->where('reference', $reference)
            ->where('phone', $phone)
            ->first();

        return view('tracking', [
            'inquiry' => $inquiry,
            'reference' => $reference,
            'searched' => true,
        ]);
    }
}

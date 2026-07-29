<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\BrandingService;
use App\Support\Terbilang;
use Illuminate\View\View;

class PublicReceiptController extends Controller
{
    public function show(string $token, BrandingService $brandingService): View
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{64}$/', $token) === 1, 404);

        $payment = Payment::query()
            ->where('public_token', $token)
            ->with(['invoice', 'creator', 'category', 'cancelledBy', 'lastEditedBy'])
            ->firstOrFail();

        return view('receipts.show', [
            'payment' => $payment,
            'branding' => $brandingService->document(),
            'amountInWords' => Terbilang::rupiah($payment->amount),
        ]);
    }
}

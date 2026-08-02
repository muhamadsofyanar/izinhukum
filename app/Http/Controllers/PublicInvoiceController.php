<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\BrandingService;
use App\Services\FeatureFlagService;
use Illuminate\View\View;

class PublicInvoiceController extends Controller
{
    public function show(
        string $token,
        BrandingService $brandingService,
        FeatureFlagService $features,
    ): View
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{56}$/', $token) === 1, 404);

        $invoice = Invoice::query()
            ->where('public_token', $token)
            ->with(['items.package.service', 'creator', 'payments', 'paymentProofs.payment'])
            ->firstOrFail();

        return view('invoice-public', [
            'invoice' => $invoice,
            'branding' => $brandingService->document(),
            'proofUploadEnabled' => $features->enabled('payment_proof_upload'),
        ]);
    }
}

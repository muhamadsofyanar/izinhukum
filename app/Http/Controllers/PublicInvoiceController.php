<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\BrandingService;
use App\Services\FeatureFlagService;
use Illuminate\Support\Facades\Schema;
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

        $relations = ['items.package.service', 'creator', 'payments'];
        if (Schema::hasTable('payment_proofs')) {
            $relations[] = 'paymentProofs.payment';
        }
        $invoice = Invoice::query()
            ->where('public_token', $token)
            ->with($relations)
            ->firstOrFail();
        if (! Schema::hasTable('payment_proofs')) {
            $invoice->setRelation('paymentProofs', collect());
        }

        return view('invoice-public', [
            'invoice' => $invoice,
            'branding' => $brandingService->document(),
            'proofUploadEnabled' => Schema::hasTable('payment_proofs') && $features->enabled('payment_proof_upload'),
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\BrandingService;
use Illuminate\View\View;

class PublicInvoiceController extends Controller
{
    public function show(string $token, BrandingService $brandingService): View
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{56}$/', $token) === 1, 404);

        $invoice = Invoice::query()
            ->where('public_token', $token)
            ->with(['items.package.service', 'creator', 'payments'])
            ->firstOrFail();

        return view('invoice-public', [
            'invoice' => $invoice,
            'branding' => $brandingService->document(),
        ]);
    }
}

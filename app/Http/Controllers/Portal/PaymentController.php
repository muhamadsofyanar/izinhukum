<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\InvoicePaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function store(
        Request $request,
        Invoice $invoice,
        InvoicePaymentService $paymentService,
    ): RedirectResponse {
        $data = $request->validate([
            'financial_category_id' => [
                'nullable',
                Rule::exists('financial_categories', 'id')
                    ->where(fn ($query) => $query->where('type', 'income')->where('is_active', true)),
            ],
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'integer', 'min:1'],
            'payment_method' => ['required', Rule::in(['transfer', 'cash', 'card', 'ewallet', 'other'])],
            'reference_number' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $payment = $paymentService->record(
            $invoice,
            $request->attributes->get('currentUser'),
            $data,
        );

        return back()->with([
            'success' => 'Pembayaran dicatat dan kwitansi berhasil dibuat.',
            'receipt_url' => route('receipts.public', $payment->public_token),
        ]);
    }
}

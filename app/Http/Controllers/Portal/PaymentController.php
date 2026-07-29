<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\FinancialCategory;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\InvoicePaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function store(
        Request $request,
        Invoice $invoice,
        InvoicePaymentService $paymentService,
    ): RedirectResponse {
        $data = $this->validatedPayment($request);

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

    public function edit(Request $request, Payment $payment): View
    {
        $payment->load(['invoice', 'category', 'creator', 'cancelledBy', 'lastEditedBy']);

        return view('portal.payments.edit', [
            'payment' => $payment,
            'categories' => FinancialCategory::query()
                ->where('type', 'income')
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function update(
        Request $request,
        Payment $payment,
        InvoicePaymentService $paymentService,
    ): RedirectResponse {
        $data = $this->validatedPayment($request, true);
        $updated = $paymentService->update(
            $payment,
            $request->attributes->get('currentUser'),
            $data,
            $request->ip(),
        );

        return redirect()
            ->to($this->returnUrl($updated))
            ->with('success', 'Kwitansi berhasil dikoreksi dan perubahan tercatat pada audit log.');
    }

    public function cancel(
        Request $request,
        Payment $payment,
        InvoicePaymentService $paymentService,
    ): RedirectResponse {
        $data = $request->validate([
            'cancellation_reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $cancelled = $paymentService->cancel(
            $payment,
            $request->attributes->get('currentUser'),
            $data['cancellation_reason'],
            $request->ip(),
        );

        return redirect()
            ->to($this->returnUrl($cancelled))
            ->with('success', 'Kwitansi dibatalkan. Baris transaksi tetap tersimpan sebagai jejak audit.');
    }

    private function validatedPayment(Request $request, bool $requiresReason = false): array
    {
        return $request->validate([
            'financial_category_id' => [
                'nullable',
                Rule::exists('financial_categories', 'id')
                    ->where(fn ($query) => $query
                        ->where('type', 'income')
                        ->where('is_active', true)),
            ],
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'integer', 'min:1'],
            'payer_name' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:255'],
            'payment_method' => [
                'required',
                Rule::in(['transfer', 'cash', 'card', 'ewallet', 'other']),
            ],
            'reference_number' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'edit_reason' => [
                $requiresReason ? 'required' : 'nullable',
                'string',
                'min:5',
                'max:1000',
            ],
        ]);
    }

    private function returnUrl(Payment $payment): string
    {
        if ($payment->invoice_id) {
            return route('admin.invoices.show', $payment->invoice_id);
        }

        return route('admin.finance.index');
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentProof;
use App\Models\User;
use App\Services\InvoicePaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentProofController extends Controller
{
    public function download(PaymentProof $proof): StreamedResponse
    {
        abort_unless(Storage::disk($proof->disk)->exists($proof->path), 404);

        return Storage::disk($proof->disk)->download($proof->path, $proof->original_name);
    }

    public function review(
        Request $request,
        PaymentProof $proof,
        InvoicePaymentService $payments,
    ): RedirectResponse {
        $data = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject'])],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);
        if ($data['action'] === 'reject' && mb_strlen(trim((string) ($data['review_note'] ?? ''))) < 5) {
            throw ValidationException::withMessages(['review_note' => 'Tuliskan alasan penolakan minimal 5 karakter.']);
        }
        $actor = $request->attributes->get('currentUser');
        abort_unless($actor instanceof User && $actor->isAdmin(), 403);

        DB::transaction(function () use ($proof, $data, $actor, $payments): void {
            $locked = PaymentProof::query()->with('invoice.payments')->lockForUpdate()->findOrFail($proof->id);
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['proof' => 'Bukti ini sudah pernah diperiksa.']);
            }

            if ($data['action'] === 'reject') {
                $locked->update([
                    'status' => 'rejected',
                    'reviewed_by' => $actor->id,
                    'reviewed_at' => now(),
                    'review_note' => trim((string) $data['review_note']),
                ]);

                return;
            }

            if ((int) $locked->claimed_amount > $locked->invoice->remainingAmount()) {
                throw ValidationException::withMessages([
                    'proof' => 'Nominal bukti melebihi sisa tagihan saat ini. Tolak bukti atau periksa pembayaran lain terlebih dahulu.',
                ]);
            }
            $payment = $payments->record($locked->invoice, $actor, [
                'payment_date' => $locked->transfer_date->toDateString(),
                'amount' => (int) $locked->claimed_amount,
                'payment_method' => 'transfer',
                'reference_number' => $locked->bank_reference,
                'notes' => trim('Disetujui dari bukti pembayaran #'.$locked->id.'. '.($data['review_note'] ?? '')),
                'source' => 'payment_proof',
                'source_key' => 'payment-proof:'.$locked->id,
            ]);
            $locked->update([
                'status' => 'approved',
                'payment_id' => $payment->id,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_note' => trim((string) ($data['review_note'] ?? '')) ?: null,
            ]);
        });

        return back()->with('success', $data['action'] === 'approve'
            ? 'Bukti disetujui; pembayaran dan kwitansi dibuat otomatis.'
            : 'Bukti pembayaran ditolak dengan alasan tersimpan.');
    }
}

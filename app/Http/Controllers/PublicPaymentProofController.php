<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\PaymentProof;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PublicPaymentProofController extends Controller
{
    public function store(Request $request, string $token): RedirectResponse
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{56}$/', $token) === 1, 404);
        $invoice = Invoice::query()->where('public_token', $token)->with('payments')->firstOrFail();

        if (in_array($invoice->status, ['draft', 'cancelled', 'paid'], true) || $invoice->remainingAmount() <= 0) {
            throw ValidationException::withMessages([
                'proof' => 'Bukti pembayaran tidak dapat dikirim untuk status invoice ini.',
            ]);
        }

        $data = $request->validate([
            'payer_name' => ['required', 'string', 'max:160'],
            'transfer_date' => ['required', 'date', 'before_or_equal:today'],
            'claimed_amount' => ['required', 'integer', 'min:1'],
            'bank_reference' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'proof_file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ]);
        if ((int) $data['claimed_amount'] > $invoice->remainingAmount()) {
            throw ValidationException::withMessages([
                'claimed_amount' => 'Nominal tidak boleh melebihi sisa tagihan Rp'.number_format($invoice->remainingAmount(), 0, ',', '.').'.',
            ]);
        }

        $file = $request->file('proof_file');
        $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $path = $file->storeAs(
            'payment-proofs/'.$invoice->id,
            Str::uuid().'.'.$extension,
            'local',
        );
        $absolutePath = Storage::disk('local')->path($path);
        $checksum = is_file($absolutePath) ? hash_file('sha256', $absolutePath) : null;

        if ($checksum && PaymentProof::query()->where('invoice_id', $invoice->id)->where('checksum', $checksum)->where('status', '!=', 'rejected')->exists()) {
            Storage::disk('local')->delete($path);
            throw ValidationException::withMessages(['proof_file' => 'Bukti yang sama sudah pernah dikirim.']);
        }

        PaymentProof::query()->create([
            'invoice_id' => $invoice->id,
            'status' => 'pending',
            'payer_name' => $data['payer_name'],
            'transfer_date' => $data['transfer_date'],
            'claimed_amount' => (int) $data['claimed_amount'],
            'bank_reference' => $data['bank_reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'disk' => 'local',
            'path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 255, ''),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'checksum' => $checksum,
        ]);

        return back()->with('success', 'Bukti pembayaran berhasil dikirim dan menunggu pemeriksaan admin.');
    }
}

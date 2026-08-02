@extends('layouts.admin')

@php
    $prefix = $user->isAdmin() ? 'admin' : 'partner';
    $canMutate = $user->isAdmin() || $invoice->created_by === $user->id;
    $canEditDraft = $canMutate && $invoice->status === 'draft' && $invoice->payments->isEmpty();
@endphp

@section('title', $invoice->invoice_number)
@section('heading', $invoice->invoice_number)

@section('header_action')
<a class="btn btn-outline-primary" href="{{ route('invoices.public', $invoice->public_token) }}" target="_blank">Cetak / Simpan PDF</a>
@endsection

@section('content')
<div class="invoice-actions">
    @if($canEditDraft)
        <a class="btn btn-primary" href="{{ route($prefix.'.invoices.edit', $invoice) }}">Ubah invoice</a>
        <form action="{{ route($prefix.'.invoices.status', $invoice) }}" method="post">
            @csrf
            @method('PUT')
            <input type="hidden" name="status" value="sent">
            <button class="btn btn-outline-primary" type="submit">Tandai terkirim & kunci</button>
        </form>
        <form action="{{ route($prefix.'.invoices.destroy', $invoice) }}" method="post" onsubmit="return confirm('Hapus permanen invoice draf ini?');">
            @csrf
            @method('DELETE')
            <button class="btn btn-outline-danger" type="submit">Hapus draf</button>
        </form>
    @endif

    @if($invoice->recipient_email && $canMutate && $invoice->status !== 'cancelled')
        <form action="{{ route($prefix.'.invoices.send', $invoice) }}" method="post">
            @csrf
            <button class="btn btn-outline-primary" type="submit">Kirim melalui email</button>
        </form>
    @endif
</div>

@if($invoice->status === 'cancelled')
    <div class="alert alert-danger">
        <strong>Invoice dibatalkan.</strong>
        <p class="mb-1 mt-2">{{ $invoice->cancellation_reason }}</p>
        <small>{{ $invoice->cancelled_at?->translatedFormat('d F Y H:i') }}{{ $invoice->cancelledBy ? ' · '.$invoice->cancelledBy->name : '' }}</small>
    </div>
@elseif($canMutate && $invoice->status === 'sent')
    <details class="admin-panel transaction-cancel-panel">
        <summary>Batalkan invoice terkirim</summary>
        <form class="p-4 stack-form" action="{{ route($prefix.'.invoices.cancel', $invoice) }}" method="post" onsubmit="return confirm('Batalkan invoice ini? Tindakan akan masuk ke audit log.');">
            @csrf
            <p class="text-muted mb-0">Invoice terkirim tidak dapat diedit atau dihapus. Gunakan pembatalan jika dokumen tidak berlaku.</p>
            <label class="field">
                <span>Alasan pembatalan *</span>
                <textarea class="form-control" name="cancellation_reason" rows="3" required>{{ old('cancellation_reason') }}</textarea>
            </label>
            <button class="btn btn-outline-danger">Batalkan invoice</button>
        </form>
    </details>
@endif

@if($invoice->referredByPartner)
    <div class="admin-note mb-3">
        Sumber pemasaran:
        <strong>{{ $invoice->referredByPartner->name }}</strong>
        · {{ $invoice->referral_code ?: $invoice->referredByPartner->partner_code }}
        @if($invoice->inquiry)
            · Proposal {{ $invoice->inquiry->reference }}
        @endif
    </div>
@endif

@if($invoice->coupon_code)
    <div class="admin-note mb-3">
        Kupon promo: <strong>{{ $invoice->coupon_code }}</strong> · potongan
        <strong>Rp{{ number_format($invoice->discount, 0, ',', '.') }}</strong>.
    </div>
@endif

<section class="invoice-sheet admin-invoice">
    @include('portal.invoices._document')
</section>

@if($user->isAdmin())
@if($invoice->paymentProofs->isNotEmpty())
<section class="admin-panel mt-3">
    <div class="admin-panel-head"><h2>Bukti pembayaran pelanggan</h2><strong>{{ $invoice->paymentProofs->where('status', 'pending')->count() }} menunggu</strong></div>
    <div class="table-responsive"><table class="table admin-table"><thead><tr><th>Pengirim</th><th>Transfer</th><th>File</th><th>Status</th><th>Tindakan</th></tr></thead><tbody>
    @foreach($invoice->paymentProofs as $proof)<tr><td><strong>{{ $proof->payer_name }}</strong><small>{{ $proof->bank_reference ?: 'Tanpa referensi bank' }}</small>@if($proof->notes)<small>{{ $proof->notes }}</small>@endif</td><td>{{ $proof->transfer_date->format('d/m/Y') }}<small><strong>Rp{{ number_format($proof->claimed_amount,0,',','.') }}</strong></small></td><td><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.payment-proofs.download', $proof) }}">Unduh {{ $proof->original_name }}</a><small>{{ number_format($proof->size / 1024, 0) }} KB</small></td><td><span class="status status-{{ $proof->status === 'approved' ? 'paid' : ($proof->status === 'rejected' ? 'cancelled' : 'sent') }}">{{ $proof->statusLabel() }}</span>@if($proof->reviewer)<small>{{ $proof->reviewer->name }} · {{ $proof->reviewed_at?->format('d/m/Y H:i') }}</small>@endif@if($proof->review_note)<small>{{ $proof->review_note }}</small>@endif</td><td>
        @if($proof->status === 'pending')<form class="proof-review-form" method="post" action="{{ route('admin.payment-proofs.review', $proof) }}">@csrf<textarea class="form-control form-control-sm" name="review_note" rows="2" placeholder="Catatan/alasan penolakan"></textarea><div class="d-flex gap-1 mt-1"><button class="btn btn-sm btn-primary" name="action" value="approve" onclick="return confirm('Setujui bukti dan buat pembayaran/kwitansi?')">Setujui</button><button class="btn btn-sm btn-outline-danger" name="action" value="reject">Tolak</button></div></form>@elseif($proof->payment)<a class="btn btn-sm btn-outline-primary" href="{{ route('receipts.public', $proof->payment->public_token) }}" target="_blank">Kwitansi</a>@endif
    </td></tr>@endforeach
    </tbody></table></div>
</section>
@endif
<section class="admin-panel mt-3">
    <div class="admin-panel-head">
        <h2>Catat pembayaran</h2>
        <strong>Sisa Rp{{ number_format($invoice->remainingAmount(), 0, ',', '.') }}</strong>
    </div>
    @if($invoice->status === 'draft')
        <div class="p-4">Tandai invoice sebagai terkirim sebelum mencatat pembayaran.</div>
    @elseif($invoice->status === 'cancelled')
        <div class="p-4">Invoice dibatalkan. Pembayaran tidak dapat dicatat.</div>
    @elseif($invoice->remainingAmount() > 0)
        <form class="p-4 form-grid" method="post" action="{{ route('admin.invoices.payments.store', $invoice) }}">
            @csrf
            <label class="field">
                <span>Tanggal pembayaran</span>
                <input class="form-control" type="date" name="payment_date" value="{{ old('payment_date', now()->format('Y-m-d')) }}" required>
            </label>
            <label class="field">
                <span>Nominal</span>
                <input class="form-control" type="number" name="amount" min="1" max="{{ $invoice->remainingAmount() }}" value="{{ old('amount', $invoice->remainingAmount()) }}" required>
            </label>
            <label class="field">
                <span>Metode</span>
                <select class="form-select" name="payment_method" required>
                    @foreach(['transfer'=>'Transfer bank','cash'=>'Tunai','card'=>'Kartu','ewallet'=>'Dompet digital','other'=>'Lainnya'] as $value=>$label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="field">
                <span>Kategori pemasukan</span>
                <select class="form-select" name="financial_category_id">
                    <option value="">Pendapatan invoice</option>
                    @foreach($incomeCategories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="field">
                <span>Nomor referensi</span>
                <input class="form-control" name="reference_number" value="{{ old('reference_number') }}">
            </label>
            <label class="field field-wide">
                <span>Catatan</span>
                <textarea class="form-control" name="notes" rows="2">{{ old('notes') }}</textarea>
            </label>
            <div><button class="btn btn-primary">Simpan pembayaran & buat kwitansi</button></div>
        </form>
    @else
        <div class="p-4"><span class="status status-paid">Lunas</span> Seluruh pembayaran aktif sudah tercatat.</div>
    @endif
</section>
@endif

@if($invoice->payments->isNotEmpty())
<section class="admin-panel mt-3">
    <div class="admin-panel-head">
        <h2>Riwayat pembayaran</h2>
        <strong>Aktif Rp{{ number_format($invoice->amountPaid(), 0, ',', '.') }}</strong>
    </div>
    <div class="table-responsive">
        <table class="table admin-table">
            <thead><tr><th>Kwitansi</th><th>Tanggal</th><th>Metode</th><th>Referensi</th><th>Nominal</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @foreach($invoice->payments->sortByDesc('payment_date') as $payment)
                <tr class="{{ $payment->isCancelled() ? 'transaction-cancelled' : '' }}">
                    <td>
                        <a href="{{ route('receipts.public', $payment->public_token) }}" target="_blank">{{ $payment->receipt_number }}</a>
                        @if($payment->last_edited_at)<small>Dikoreksi {{ $payment->last_edited_at->format('d/m/Y H:i') }}</small>@endif
                    </td>
                    <td>{{ $payment->payment_date->format('d/m/Y') }}</td>
                    <td>{{ ucfirst($payment->payment_method) }}</td>
                    <td>{{ $payment->reference_number ?: '—' }}</td>
                    <td><strong>{{ $payment->formattedAmount() }}</strong></td>
                    <td>
                        <span class="status status-{{ $payment->isCancelled() ? 'cancelled' : 'paid' }}">
                            {{ $payment->isCancelled() ? 'Dibatalkan' : 'Aktif' }}
                        </span>
                        @if($payment->isCancelled())<small>{{ $payment->cancellation_reason }}</small>@endif
                    </td>
                    <td>
                        @if($user->isAdmin())
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.payments.edit', $payment) }}">{{ $payment->isCancelled() ? 'Detail' : 'Koreksi' }}</a>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</section>
@endif

<div class="admin-note mt-3">Invoice draf dapat diedit atau dihapus. Setelah diterbitkan, gunakan pembatalan beralasan agar histori transaksi tetap dapat diaudit.</div>
@endsection

@extends('layouts.admin')

@php($prefix = $user->isAdmin() ? 'admin' : 'partner')
@section('title', $invoice->invoice_number)
@section('heading', $invoice->invoice_number)

@section('header_action')
<a class="btn btn-outline-primary" href="{{ route('invoices.public', $invoice->public_token) }}" target="_blank">Cetak / Simpan PDF</a>
@endsection

@section('content')
<div class="invoice-actions">
    @if($invoice->recipient_email && ($user->isAdmin() || $invoice->created_by === $user->id))
        <form action="{{ route($prefix.'.invoices.send', $invoice) }}" method="post">@csrf<button class="btn btn-primary" type="submit">Kirim melalui email</button></form>
    @endif
    @if(($user->isAdmin() || $invoice->created_by === $user->id) && !in_array($invoice->status, ['partial', 'paid'], true))
        <form class="d-flex gap-2" action="{{ route($prefix.'.invoices.status', $invoice) }}" method="post">
            @csrf @method('PUT')
            <select class="form-select" name="status">
                @foreach(['draft' => 'Draf', 'sent' => 'Terkirim', 'cancelled' => 'Dibatalkan'] as $value => $label)
                    <option value="{{ $value }}" @selected($invoice->status === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <button class="btn btn-outline-primary" type="submit">Ubah status</button>
        </form>
    @endif
</div>
<section class="invoice-sheet admin-invoice">
    @include('portal.invoices._document')
</section>
@if($user->isAdmin())
<section class="admin-panel mt-3">
    <div class="admin-panel-head"><h2>Catat pembayaran</h2><strong>Sisa Rp{{ number_format($invoice->remainingAmount(), 0, ',', '.') }}</strong></div>
    @if($invoice->status === 'cancelled')
        <div class="p-4">Invoice dibatalkan. Pembayaran tidak dapat dicatat.</div>
    @elseif($invoice->remainingAmount() > 0)
        <form class="p-4 form-grid" method="post" action="{{ route('admin.invoices.payments.store', $invoice) }}">
            @csrf
            <label class="field"><span>Tanggal pembayaran</span><input class="form-control" type="date" name="payment_date" value="{{ old('payment_date', now()->format('Y-m-d')) }}" required></label>
            <label class="field"><span>Nominal</span><input class="form-control" type="number" name="amount" min="1" max="{{ $invoice->remainingAmount() }}" value="{{ old('amount', $invoice->remainingAmount()) }}" required></label>
            <label class="field"><span>Metode</span><select class="form-select" name="payment_method" required>@foreach(['transfer'=>'Transfer bank','cash'=>'Tunai','card'=>'Kartu','ewallet'=>'Dompet digital','other'=>'Lainnya'] as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
            <label class="field"><span>Kategori pemasukan</span><select class="form-select" name="financial_category_id"><option value="">Pendapatan invoice</option>@foreach($incomeCategories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></label>
            <label class="field"><span>Nomor referensi</span><input class="form-control" name="reference_number" value="{{ old('reference_number') }}"></label>
            <label class="field field-wide"><span>Catatan</span><textarea class="form-control" name="notes" rows="2">{{ old('notes') }}</textarea></label>
            <div><button class="btn btn-primary">Simpan pembayaran & buat kwitansi</button></div>
        </form>
    @else
        <div class="p-4"><span class="status status-paid">Lunas</span> Seluruh pembayaran sudah tercatat.</div>
    @endif
</section>
@endif
@if($invoice->payments->isNotEmpty())
<section class="admin-panel mt-3">
    <div class="admin-panel-head"><h2>Riwayat pembayaran</h2><strong>Rp{{ number_format($invoice->amountPaid(), 0, ',', '.') }}</strong></div>
    <div class="table-responsive"><table class="table admin-table"><thead><tr><th>Kwitansi</th><th>Tanggal</th><th>Metode</th><th>Referensi</th><th>Nominal</th></tr></thead><tbody>
    @foreach($invoice->payments as $payment)<tr><td><a href="{{ route('receipts.public', $payment->public_token) }}" target="_blank">{{ $payment->receipt_number }}</a></td><td>{{ $payment->payment_date->format('d/m/Y') }}</td><td>{{ ucfirst($payment->payment_method) }}</td><td>{{ $payment->reference_number ?: '—' }}</td><td><strong>{{ $payment->formattedAmount() }}</strong></td></tr>@endforeach
    </tbody></table></div>
</section>
@endif
<div class="admin-note mt-3">Tautan publik invoice dapat dibuka penerima tanpa login. Hanya bagikan kepada pihak yang dituju.</div>
@endsection

@extends('layouts.admin')

@section('title', 'Koreksi '.$payment->receipt_number)
@section('heading', 'Koreksi Kwitansi')

@section('header_action')
<a class="btn btn-outline-primary" href="{{ route('receipts.public', $payment->public_token) }}" target="_blank">Lihat kwitansi</a>
@endsection

@section('content')
<div class="finance-entry-grid">
    <section class="admin-panel">
        <div class="admin-panel-head">
            <h2>{{ $payment->receipt_number }}</h2>
            <span class="status status-{{ $payment->isCancelled() ? 'cancelled' : 'paid' }}">
                {{ $payment->isCancelled() ? 'Dibatalkan' : 'Aktif' }}
            </span>
        </div>

        @if($payment->isCancelled())
            <div class="p-4">
                <div class="alert alert-danger">
                    <strong>Kwitansi telah dibatalkan.</strong>
                    <p class="mb-0 mt-2">{{ $payment->cancellation_reason }}</p>
                </div>
                <p class="mb-0">Dibatalkan {{ $payment->cancelled_at?->translatedFormat('d F Y H:i') }}
                    @if($payment->cancelledBy) oleh {{ $payment->cancelledBy->name }} @endif
                </p>
            </div>
        @else
            <form class="p-4 stack-form" method="post" action="{{ route('admin.payments.update', $payment) }}">
                @csrf
                @method('PUT')
                <div class="form-grid">
                    <label class="field">
                        <span>Tanggal pembayaran</span>
                        <input class="form-control" type="date" name="payment_date" value="{{ old('payment_date', $payment->payment_date->format('Y-m-d')) }}" required>
                    </label>
                    <label class="field">
                        <span>Nominal</span>
                        <input class="form-control" type="number" name="amount" min="1" value="{{ old('amount', $payment->amount) }}" required>
                    </label>
                    <label class="field">
                        <span>Diterima dari</span>
                        <input class="form-control" name="payer_name" value="{{ old('payer_name', $payment->payer_name) }}">
                    </label>
                    <label class="field">
                        <span>Metode</span>
                        <select class="form-select" name="payment_method" required>
                            @foreach(['transfer'=>'Transfer bank','cash'=>'Tunai','card'=>'Kartu','ewallet'=>'Dompet digital','other'=>'Lainnya'] as $value=>$label)
                                <option value="{{ $value }}" @selected(old('payment_method', $payment->payment_method) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="field">
                        <span>Kategori pemasukan</span>
                        <select class="form-select" name="financial_category_id">
                            <option value="">Pendapatan invoice/pemasukan lain</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}" @selected(old('financial_category_id', $payment->financial_category_id) == $category->id)>{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="field">
                        <span>Nomor referensi</span>
                        <input class="form-control" name="reference_number" value="{{ old('reference_number', $payment->reference_number) }}">
                    </label>
                    <label class="field field-wide">
                        <span>Uraian</span>
                        <input class="form-control" name="description" value="{{ old('description', $payment->description) }}">
                    </label>
                    <label class="field field-wide">
                        <span>Catatan</span>
                        <textarea class="form-control" name="notes" rows="2">{{ old('notes', $payment->notes) }}</textarea>
                    </label>
                    <label class="field field-wide">
                        <span>Alasan koreksi *</span>
                        <textarea class="form-control" name="edit_reason" rows="2" required placeholder="Jelaskan data yang diperbaiki dan alasannya.">{{ old('edit_reason') }}</textarea>
                    </label>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-primary">Simpan koreksi</button>
                    <a class="btn btn-outline-secondary" href="{{ $payment->invoice_id ? route('admin.invoices.show', $payment->invoice_id) : route('admin.finance.index') }}">Batal</a>
                </div>
            </form>
        @endif
    </section>

    @if(! $payment->isCancelled())
    <aside class="admin-panel">
        <div class="admin-panel-head"><h2>Batalkan kwitansi</h2></div>
        <form class="p-4 stack-form" method="post" action="{{ route('admin.payments.cancel', $payment) }}" onsubmit="return confirm('Batalkan kwitansi ini? Transaksi akan dikeluarkan dari laporan keuangan.');">
            @csrf
            <p class="text-muted">Pembatalan tidak menghapus data. Status invoice dan laporan keuangan akan dihitung ulang.</p>
            <label class="field">
                <span>Alasan pembatalan *</span>
                <textarea class="form-control" name="cancellation_reason" rows="4" required placeholder="Contoh: pembayaran salah dicatat atau transaksi dikembalikan.">{{ old('cancellation_reason') }}</textarea>
            </label>
            <button class="btn btn-outline-danger">Batalkan kwitansi</button>
        </form>
    </aside>
    @endif
</div>
@endsection

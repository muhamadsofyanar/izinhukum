@extends('layouts.admin')

@section('title', 'Ringkasan Mitra')
@section('heading', 'Ringkasan Mitra')

@section('header_action')
<a class="btn btn-primary" href="{{ route('partner.invoices.create') }}">Buat invoice</a>
@endsection

@section('content')
<div class="admin-stats">
    <article><span>Paket tersedia</span><strong>{{ $activePackages }}</strong></article>
    <article><span>Invoice dibuat</span><strong>{{ $createdInvoices }}</strong></article>
    <article><span>Tagihan dari IzinHukum</span><strong>{{ $incomingInvoices }}</strong></article>
    <article><span>Invoice lunas</span><strong>{{ $paidInvoices }}</strong></article>
</div>
<section class="admin-panel">
    <div class="admin-panel-head"><h2>Invoice terbaru</h2><a href="{{ route('partner.invoices.index') }}">Lihat semua →</a></div>
    <div class="table-responsive">
        <table class="table admin-table">
            <thead><tr><th>Nomor</th><th>Penerima</th><th>Total</th><th>Status</th><th>Tanggal</th></tr></thead>
            <tbody>
            @forelse($latestInvoices as $invoice)
                <tr>
                    <td><a href="{{ route('partner.invoices.show', $invoice) }}">{{ $invoice->invoice_number }}</a></td>
                    <td><strong>{{ $invoice->recipient_name }}</strong><small>{{ $invoice->recipient_company }}</small></td>
                    <td>{{ $invoice->formattedTotal() }}</td>
                    <td><span class="status status-{{ $invoice->status }}">{{ ucfirst($invoice->status) }}</span></td>
                    <td>{{ $invoice->created_at->format('d/m/Y') }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center py-5">Belum ada invoice.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection

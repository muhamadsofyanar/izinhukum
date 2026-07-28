@extends('layouts.admin')

@section('title', 'Ringkasan Mitra')
@section('heading', 'Ringkasan Mitra')

@section('header_action')
<a class="btn btn-primary" href="{{ route('partner.invoices.create') }}">Buat invoice</a>
@endsection

@section('content')
<div class="admin-stats">
    <article><span>Kelas aktif</span><strong>{{ $activeCourses }}</strong></article>
    <article><span>Kelas selesai</span><strong>{{ $completedCourses }}</strong></article>
    <article><span>Invoice dibuat</span><strong>{{ $createdInvoices }}</strong></article>
    <article><span>Komisi dibayar</span><strong>Rp{{ number_format($commissionTotal,0,',','.') }}</strong></article>
</div>
@if($announcements->isNotEmpty())<section class="admin-panel mb-4"><div class="admin-panel-head"><h2>Pengumuman terbaru</h2></div><div class="announcement-list">@foreach($announcements as $item)<article><small>{{ $item->published_at->format('d/m/Y') }}</small><h3>{{ $item->title }}</h3><p>{{ $item->body }}</p></article>@endforeach</div></section>@endif
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

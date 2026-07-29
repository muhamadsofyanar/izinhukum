@extends('layouts.admin')

@section('title', 'Ringkasan Mitra')
@section('heading', 'Ringkasan Mitra')

@section('header_action')
<a class="btn btn-primary" href="{{ route('partner.invoices.create') }}">Buat invoice</a>
@endsection

@section('content')
<div class="admin-stats">
    <article><span>Klik referral</span><strong>{{ number_format($referralClicks, 0, ',', '.') }}</strong></article>
    <article><span>Prospek referral</span><strong>{{ number_format($referralLeads, 0, ',', '.') }}</strong></article>
    <article><span>Invoice referral</span><strong>{{ number_format($referralInvoices, 0, ',', '.') }}</strong></article>
    <article><span>Omzet terbayar</span><strong>Rp{{ number_format($referralRevenue,0,',','.') }}</strong></article>
    <article><span>Komisi diproses</span><strong>Rp{{ number_format($commissionPending,0,',','.') }}</strong></article>
    <article><span>Komisi dibayar</span><strong>Rp{{ number_format($commissionTotal,0,',','.') }}</strong></article>
</div>
<section class="admin-panel mb-4">
    <div class="admin-panel-head">
        <h2>Tautan pemasaran Anda</h2>
        <span class="status status-paid">{{ $partnerPlan['name'] ?? 'Gratis' }}</span>
    </div>
    <div class="p-4">
        <p>Bagikan tautan ini. Proposal, invoice, pembayaran, dan komisi akan terhubung ke kode mitra Anda selama atribusi masih berlaku.</p>
        <div class="input-group">
            <input class="form-control" id="partner-referral-url" value="{{ $referralUrl }}" readonly>
            <button class="btn btn-primary" id="copy-partner-referral" type="button">Salin tautan</button>
        </div>
        <small class="text-muted d-block mt-2">
            Paket {{ $partnerPlan['name'] ?? 'Gratis' }} · Komisi {{ number_format(($partnerPlan['commission_bps'] ?? 0) / 100, 0, ',', '.') }}% dari pembayaran aktif.
        </small>
    </div>
</section>
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

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const button = document.getElementById('copy-partner-referral');
    const field = document.getElementById('partner-referral-url');
    button?.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(field.value);
            button.textContent = 'Tersalin';
        } catch {
            field.select();
            document.execCommand('copy');
            button.textContent = 'Tersalin';
        }
    });
});
</script>
@endpush

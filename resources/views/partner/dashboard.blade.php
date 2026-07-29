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
        <h2>Tautan referral semua layanan</h2>
        <span class="status status-paid">{{ $partnerPlan['name'] ?? 'Gratis' }}</span>
    </div>
    <div class="p-4">
        <p>
            Bagikan tautan sesuai kebutuhan calon pelanggan. Kode mitra tetap tercatat saat pengunjung membuka
            website, katalog layanan, halaman layanan tertentu, atau formulir proposal.
        </p>

        <div class="row g-3">
            @foreach([
                ['label' => 'Website utama', 'url' => $referralUrl],
                ['label' => 'Katalog semua layanan', 'url' => $servicesReferralUrl],
                ['label' => 'Form proposal langsung', 'url' => $proposalReferralUrl],
            ] as $link)
                <div class="col-12">
                    <label class="field">
                        <span>{{ $link['label'] }}</span>
                        <div class="input-group">
                            <input class="form-control" value="{{ $link['url'] }}" readonly>
                            <button
                                class="btn btn-primary referral-copy-button"
                                type="button"
                                data-copy-value="{{ $link['url'] }}"
                            >Salin</button>
                            <a class="btn btn-outline-primary" href="{{ $link['url'] }}" target="_blank" rel="noopener">Buka ↗</a>
                        </div>
                    </label>
                </div>
            @endforeach
        </div>

        <small class="text-muted d-block mt-3">
            Paket {{ $partnerPlan['name'] ?? 'Gratis' }} · Komisi
            {{ number_format(($partnerPlan['commission_bps'] ?? 0) / 100, 0, ',', '.') }}% dari pembayaran aktif.
        </small>
    </div>
</section>

<section class="admin-panel mb-4">
    <div class="admin-panel-head">
        <h2>Tautan per layanan</h2>
        <span>{{ $referralServices->count() }} layanan aktif</span>
    </div>
    <div class="p-4">
        <div class="row g-3">
            @forelse($referralServices as $serviceLink)
                <div class="col-12 col-xl-6">
                    <article class="border rounded-3 p-3 h-100">
                        <small class="text-muted">{{ $serviceLink['category'] }}</small>
                        <strong class="d-block mb-2">{{ $serviceLink['name'] }}</strong>
                        <div class="input-group">
                            <input class="form-control" value="{{ $serviceLink['url'] }}" readonly>
                            <button
                                class="btn btn-primary referral-copy-button"
                                type="button"
                                data-copy-value="{{ $serviceLink['url'] }}"
                            >Salin</button>
                            <a class="btn btn-outline-primary" href="{{ $serviceLink['url'] }}" target="_blank" rel="noopener">Buka ↗</a>
                        </div>
                    </article>
                </div>
            @empty
                <div class="col-12">
                    <p class="mb-0 text-muted">Belum ada layanan aktif yang dapat dibagikan.</p>
                </div>
            @endforelse
        </div>
    </div>
</section>

@if($announcements->isNotEmpty())
<section class="admin-panel mb-4">
    <div class="admin-panel-head"><h2>Pengumuman terbaru</h2></div>
    <div class="announcement-list">
        @foreach($announcements as $item)
            <article>
                <small>{{ $item->published_at->format('d/m/Y') }}</small>
                <h3>{{ $item->title }}</h3>
                <p>{{ $item->body }}</p>
            </article>
        @endforeach
    </div>
</section>
@endif

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
    document.querySelectorAll('.referral-copy-button').forEach((button) => {
        button.addEventListener('click', async () => {
            const value = button.dataset.copyValue || '';
            const originalLabel = button.textContent;

            try {
                await navigator.clipboard.writeText(value);
            } catch {
                const fallback = document.createElement('textarea');
                fallback.value = value;
                fallback.setAttribute('readonly', '');
                fallback.style.position = 'fixed';
                fallback.style.opacity = '0';
                document.body.appendChild(fallback);
                fallback.select();
                document.execCommand('copy');
                fallback.remove();
            }

            button.textContent = 'Tersalin';
            window.setTimeout(() => {
                button.textContent = originalLabel;
            }, 1600);
        });
    });
});
</script>
@endpush

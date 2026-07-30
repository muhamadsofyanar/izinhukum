@extends('layouts.app')

@section('title', 'Permintaan Berhasil Dikirim')
@section('content')
<section class="section success-section">
    <div class="container">
        <div class="success-card">
            <span class="success-check">✓</span>
            <span class="eyebrow">Permintaan berhasil</span>
            <h1>Terima kasih, {{ $inquiry->name }}.</h1>
            <p>Permintaan Anda sudah tercatat dan pusat order telah dibuat. Simpan nomor referensi berikut.</p>
            @if($openWhatsApp)
                <div class="alert alert-info mt-3">Membuka WhatsApp agar Anda dapat mengirim konfirmasi. Jika tidak terbuka otomatis, gunakan tombol di bawah.</div>
            @endif
            <div class="reference-box">{{ $inquiry->reference }}</div>
            @if($inquiry->package)
                <div class="success-detail"><span>Layanan</span><strong>{{ $inquiry->package->name }}</strong></div>
            @endif
            @if($inquiry->serviceOrder)
                <div class="success-detail"><span>Nomor order</span><strong>{{ $inquiry->serviceOrder->order_number }}</strong></div>
            @endif
            <div class="d-flex flex-wrap justify-content-center gap-2 mt-4">
                @if($inquiry->serviceOrder && app(\App\Services\FeatureFlagService::class)->enabled('customer_portal'))
                    <a class="btn btn-primary" href="{{ route('customer.orders.show', $inquiry->serviceOrder->public_token) }}">Buka portal pelanggan</a>
                @endif
                <a class="btn btn-outline-primary" target="_blank" rel="noopener" href="{{ $whatsappUrl }}">Kirim konfirmasi WhatsApp</a>
                <a class="btn btn-outline-primary" href="{{ route('tracking.index') }}">Lacak permintaan</a>
                <a class="btn btn-outline-primary" href="{{ route('home') }}">Kembali ke beranda</a>
            </div>
            <small class="text-muted d-block mt-4">Tautan portal bersifat rahasia karena memuat progres, invoice, kwitansi, dan dokumen order.</small>
        </div>
    </div>
</section>
@endsection

@if($openWhatsApp)
@push('scripts')
<script>
window.setTimeout(function () {
    window.location.assign(@json($whatsappUrl));
}, 700);
</script>
@endpush
@endif

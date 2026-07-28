@extends('layouts.app')

@section('title', 'Permintaan Berhasil Dikirim')

@section('content')
<section class="section success-section">
    <div class="container">
        <div class="success-card">
            <span class="success-check">✓</span>
            <span class="eyebrow">Permintaan berhasil</span>
            <h1>Terima kasih, {{ $inquiry->name }}.</h1>
            <p>Permintaan Anda sudah tercatat. Simpan nomor referensi berikut ketika menghubungi tim kami.</p>
            <div class="reference-box">{{ $inquiry->reference }}</div>
            @if($inquiry->package)
                <div class="success-detail">
                    <span>Layanan</span>
                    <strong>{{ $inquiry->package->name }}</strong>
                </div>
            @endif
            <div class="d-flex flex-wrap justify-content-center gap-2 mt-4">
                <a class="btn btn-primary" target="_blank" rel="noopener" href="https://wa.me/{{ config('company.whatsapp') }}?text={{ urlencode('Halo IzinHukum, saya sudah mengirim permintaan dengan nomor '.$inquiry->reference.'.') }}">Konfirmasi via WhatsApp</a>
                <a class="btn btn-outline-primary" href="{{ route('tracking.index', ['reference' => $inquiry->reference, 'phone' => $inquiry->phone]) }}">Lacak permintaan</a>
                <a class="btn btn-outline-primary" href="{{ route('home') }}">Kembali ke beranda</a>
            </div>
        </div>
    </div>
</section>
@endsection

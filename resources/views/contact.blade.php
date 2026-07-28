@extends('layouts.app')

@section('title', 'Kontak IzinHukum')
@section('meta_description', 'Hubungi PT Praktisi Izin Hukum untuk konsultasi legalitas badan usaha dan layanan hukum.')

@section('content')
<section class="page-hero">
    <div class="container">
        <span class="eyebrow">Hubungi kami</span>
        <h1>Konsultasikan kebutuhan Anda</h1>
        <p>Tim IzinHukum siap membantu menjelaskan layanan, dokumen awal, dan langkah yang perlu Anda siapkan.</p>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="row g-4">
            <div class="col-md-6 col-lg-4">
                <article class="contact-card">
                    <span class="contact-label">WhatsApp & telepon</span>
                    <h2>{{ config('company.phone') }}</h2>
                    <p>Untuk konsultasi awal dan pembaruan proses.</p>
                    <a class="text-link" target="_blank" rel="noopener" href="https://wa.me/{{ config('company.whatsapp') }}">Mulai chat →</a>
                </article>
            </div>
            <div class="col-md-6 col-lg-4">
                <article class="contact-card">
                    <span class="contact-label">Email</span>
                    <h2>{{ config('company.email') }}</h2>
                    <p>Untuk korespondensi, dokumen, dan kebutuhan kerja sama.</p>
                    <a class="text-link" href="mailto:{{ config('company.email') }}">Kirim email →</a>
                </article>
            </div>
            <div class="col-lg-4">
                <article class="contact-card">
                    <span class="contact-label">Kantor</span>
                    <h2>Jatinangor, Sumedang</h2>
                    <p>{{ config('company.address') }}</p>
                </article>
            </div>
        </div>

        <div class="payment-panel">
            <div>
                <span class="eyebrow">Rekening pembayaran resmi</span>
                <h2>{{ config('company.bank.name') }} {{ config('company.bank.account') }}</h2>
                <p>a.n. {{ config('company.bank.holder') }}</p>
            </div>
            <div class="payment-warning">
                <strong>Hindari salah transfer</strong>
                <p>Pastikan Anda telah menerima invoice dan mengonfirmasi rekening melalui kontak resmi kami sebelum melakukan pembayaran.</p>
            </div>
        </div>
    </div>
</section>
@endsection

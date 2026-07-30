<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Jasa Legalitas Badan Usaha') · IzinHukum</title>
    <meta name="description" content="@yield('meta_description', 'IzinHukum membantu pengurusan legalitas badan usaha, badan hukum, OSS, NIB, dan kekayaan intelektual hingga tuntas.')">
    <meta name="theme-color" content="#07192f">
    <meta property="og:type" content="website">
    <meta property="og:title" content="@yield('title', 'IzinHukum · Legalitas Sampai Tuntas')">
    <meta property="og:description" content="@yield('meta_description', 'Layanan legalitas bisnis yang praktis, aman, dan transparan.')">
    <meta property="og:url" content="{{ url()->current() }}">
    @vite(['resources/css/app.scss', 'resources/js/app.js'])
    <link rel="stylesheet" href="{{ asset('css/admin-fixes.css') }}?v=14.0.0">
</head>
@php($featureFlags = app(\App\Services\FeatureFlagService::class))
<body>
    <a class="skip-link" href="#main">Lewati ke konten</a>
    <div class="utility-bar">
        <div class="container d-flex flex-wrap align-items-center justify-content-between gap-2">
            <span>Konsultasi awal gratis · Melayani seluruh Indonesia</span>
            <div class="d-flex gap-3">
                <a href="tel:{{ config('company.phone') }}">{{ config('company.phone') }}</a>
                <a href="mailto:{{ config('company.email') }}">{{ config('company.email') }}</a>
            </div>
        </div>
    </div>
    <header class="site-header sticky-top">
        <nav class="navbar navbar-expand-lg" aria-label="Navigasi utama">
            <div class="container">
                <a class="brand" href="{{ route('home') }}" aria-label="IzinHukum beranda">
                    <span class="brand-mark">IH</span>
                    <span class="brand-copy"><strong>IzinHukum</strong><small>Legalitas sampai tuntas</small></span>
                </a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Buka menu">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="mainNav">
                    <ul class="navbar-nav ms-auto align-items-lg-center">
                        <li class="nav-item dropdown dropdown-mega">
                            <a class="nav-link dropdown-toggle" href="{{ route('services.index') }}" data-bs-toggle="dropdown" data-bs-auto-close="outside">Layanan</a>
                            <div class="dropdown-menu mega-menu">
                                <div class="row g-4">
                                    @forelse($navServices as $category => $items)
                                        <div class="col-md-6">
                                            <p class="mega-heading">{{ $category }}</p>
                                            @foreach($items as $navService)
                                                <a class="mega-link" href="{{ route('services.show', $navService) }}">
                                                    <span>{{ $navService->short_name }}</span>
                                                    <span aria-hidden="true">→</span>
                                                </a>
                                            @endforeach
                                        </div>
                                    @empty
                                        <div class="col-12"><a class="mega-link" href="{{ route('services.index') }}">Lihat seluruh layanan <span>→</span></a></div>
                                    @endforelse
                                </div>
                                @if($featureFlags->enabled('public_proposal'))
                                    <div class="mega-footer">
                                        <span>Belum tahu layanan yang tepat?</span>
                                        <a href="{{ route('proposal.create') }}">Konsultasikan kebutuhan Anda →</a>
                                    </div>
                                @endif
                            </div>
                        </li>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="{{ route('tools.index') }}" data-bs-toggle="dropdown">Alat Gratis</a>
                            <div class="dropdown-menu">
                                <a class="dropdown-item" href="{{ route('tools.index') }}">Semua alat</a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item" href="{{ route('tools.name-generator') }}">Generator nama</a>
                                <a class="dropdown-item" href="{{ route('tools.deed-simulator') }}">Simulasi bahan akta</a>
                                <a class="dropdown-item" href="{{ route('kbli.index') }}">Cek KBLI & risiko</a>
                            </div>
                        </li>
                        @if($featureFlags->enabled('public_articles'))<li class="nav-item"><a class="nav-link" href="{{ route('articles.index') }}">Artikel</a></li>@endif
                        @if($featureFlags->enabled('partner_registration'))<li class="nav-item"><a class="nav-link" href="{{ route('partnership.create') }}">Kemitraan</a></li>@endif
                        <li class="nav-item"><a class="nav-link" href="{{ route('contact') }}">Kontak</a></li>
                        @if($featureFlags->enabled('public_proposal'))<li class="nav-item ms-lg-3"><a class="btn btn-primary btn-sm" href="{{ route('proposal.create') }}">Minta Proposal</a></li>@endif
                    </ul>
                </div>
            </div>
        </nav>
    </header>
    @if(session('success'))
        <div class="container mt-3">
            <div class="alert alert-success" role="status">{{ session('success') }}</div>
        </div>
    @endif

    <main id="main">
        @yield('content')
    </main>
    <section class="cta-band">
        <div class="container">
            <div class="cta-panel">
                <div>
                    <span class="eyebrow eyebrow-light">Konsultasi tanpa biaya</span>
                    <h2>Masih bingung harus mulai dari mana?</h2>
                    <p>Ceritakan kebutuhan Anda. Tim kami membantu memilih layanan dan menjelaskan tahap berikutnya.</p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    @if($featureFlags->enabled('public_proposal'))<a class="btn btn-light" href="{{ route('proposal.create') }}">Minta penawaran</a>@endif
                    <a class="btn btn-outline-light" target="_blank" rel="noopener" href="https://wa.me/{{ config('company.whatsapp') }}?text={{ urlencode('Halo IzinHukum, saya ingin konsultasi legalitas.') }}">Chat WhatsApp</a>
                </div>
            </div>
        </div>
    </section>
    <footer class="site-footer">
        <div class="container">
            <div class="row g-5">
                <div class="col-lg-4">
                    <a class="brand brand-light mb-3" href="{{ route('home') }}">
                        <span class="brand-mark">IH</span>
                        <span class="brand-copy"><strong>IzinHukum</strong><small>Legalitas sampai tuntas</small></span>
                    </a>
                    <p class="footer-intro">{{ config('company.name') }} membantu individu, UMKM, organisasi, dan perusahaan mengurus legalitas secara praktis dan transparan.</p>
                </div>
                <div class="col-6 col-lg-2">
                    <h3>Jelajahi</h3>
                    <a href="{{ route('services.index') }}">Semua layanan</a>
                    <a href="{{ route('tools.index') }}">Alat gratis</a>
                    <a href="{{ route('kbli.index') }}">Cek KBLI</a>
                    @if($featureFlags->enabled('public_articles'))<a href="{{ route('articles.index') }}">Artikel</a>@endif
                    <a href="{{ route('tracking.index') }}">Lacak permintaan</a>
                    @if($featureFlags->enabled('partner_registration'))<a href="{{ route('partnership.create') }}">Kemitraan LegaOne</a>@endif
                    <a href="{{ route('partner.login') }}">Masuk mitra</a>
                    @if($featureFlags->enabled('public_proposal'))<a href="{{ route('proposal.create') }}">Minta proposal</a>@endif
                    <a href="{{ route('contact') }}">Kontak</a>
                </div>
                <div class="col-6 col-lg-3">
                    <h3>Kontak</h3>
                    <a href="tel:{{ config('company.phone') }}">{{ config('company.phone') }}</a>
                    <a href="mailto:{{ config('company.email') }}">{{ config('company.email') }}</a>
                    <p>{{ config('company.address') }}</p>
                </div>
                <div class="col-lg-3">
                    <h3>Pembayaran resmi</h3>
                    <div class="bank-card">
                        <span>{{ config('company.bank.name') }}</span>
                        <strong>{{ config('company.bank.account') }}</strong>
                        <small>a.n. {{ config('company.bank.holder') }}</small>
                    </div>
                    <p class="footer-note">Konfirmasi pembayaran hanya melalui kontak resmi IzinHukum.</p>
                </div>
            </div>
            <div class="footer-bottom">
                <span>© {{ date('Y') }} {{ config('company.name') }}. Hak cipta dilindungi.</span>
                <span><a href="{{ route('legal.privacy') }}">Privasi</a> · <a href="{{ route('legal.terms') }}">Syarat & ketentuan</a></span>
                <span>Harga dapat berubah mengikuti ruang lingkup pekerjaan dan ketentuan pemerintah.</span>
            </div>
        </div>
    </footer>
    <a class="whatsapp-float" target="_blank" rel="noopener" aria-label="Chat IzinHukum melalui WhatsApp" href="https://wa.me/{{ config('company.whatsapp') }}?text={{ urlencode('Halo IzinHukum, saya ingin konsultasi legalitas.') }}">
        <span>WA</span><strong>Konsultasi</strong>
    </a>
</body>
</html>

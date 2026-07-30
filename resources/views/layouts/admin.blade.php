<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Portal') · IzinHukum</title>
    @vite(['resources/css/app.scss', 'resources/js/app.js'])
    <link rel="stylesheet" href="{{ asset('css/admin-fixes.css') }}?v=12.0.0">
    <link rel="stylesheet" href="{{ asset('css/whatsapp-v11.css') }}?v=12.0.0">
</head>
<body class="admin-body">
@php
    $isAdmin = $currentUser->isAdmin();
    $prefix = $isAdmin ? 'admin' : 'partner';
    $operationModule = request()->route('module');
    $featureFlags = app(\App\Services\FeatureFlagService::class);
@endphp
<div class="admin-shell">
    <aside class="admin-sidebar">
        <div class="admin-sidebar-top">
            <a class="brand brand-light" href="{{ route($prefix.'.dashboard') }}">
                @if($platformBrandLogo)
                    <img class="brand-logo-image" src="{{ asset('storage/'.$platformBrandLogo) }}" alt="{{ $platformBrandName }}">
                @else
                    <span class="brand-mark">IH</span>
                @endif
                <span class="brand-copy">
                    <strong>{{ $platformBrandName }}</strong>
                    <small>{{ $isAdmin ? 'Administrator' : 'Mitra LegaOne' }}</small>
                </span>
            </a>
            <div class="portal-identity">
                <strong>{{ $currentUser->name }}</strong>
                <small>{{ $currentUser->partner_code ?: $currentUser->email }}</small>
            </div>
        </div>

        <nav class="admin-sidebar-nav" aria-label="Navigasi portal">
            <span class="sidebar-section-label">Utama</span>
            <a class="{{ request()->routeIs($prefix.'.dashboard') ? 'active' : '' }}" href="{{ route($prefix.'.dashboard') }}">Ringkasan</a>

            @if($isAdmin)
                <span class="sidebar-section-label">Penjualan</span>
                <a class="{{ request()->routeIs('admin.orders.*') ? 'active' : '' }}" href="{{ route('admin.orders.index') }}">Order layanan</a>
                <a class="{{ request()->routeIs('admin.inquiries.*') ? 'active' : '' }}" href="{{ route('admin.inquiries.index') }}">Permintaan masuk</a>
                <a class="{{ request()->routeIs('admin.packages.*') ? 'active' : '' }}" href="{{ route('admin.packages.index') }}">Harga & paket</a>

                <span class="sidebar-section-label">Keuangan</span>
                <a class="{{ request()->routeIs('admin.invoices.*') || request()->routeIs('admin.payments.*') ? 'active' : '' }}" href="{{ route('admin.invoices.index') }}">Invoice & kwitansi</a>
                <a class="{{ request()->routeIs('admin.finance.*') ? 'active' : '' }}" href="{{ route('admin.finance.index') }}">Laporan keuangan</a>
                <a class="{{ (request()->routeIs('admin.operations.*') && $operationModule === 'commissions') || request()->routeIs('admin.commissions.*') ? 'active' : '' }}" href="{{ route('admin.operations.index', 'commissions') }}">Komisi mitra</a>

                <span class="sidebar-section-label">Mitra</span>
                <a class="{{ request()->routeIs('admin.partners.*') ? 'active' : '' }}" href="{{ route('admin.partners.index') }}">Daftar & pendaftaran</a>
                <a class="{{ request()->routeIs('admin.academy.*') ? 'active' : '' }}" href="{{ route('admin.academy.index') }}">LMS Akademi</a>
                <a class="{{ request()->routeIs('admin.community.*') ? 'active' : '' }}" href="{{ route('admin.community.index') }}">Community</a>
                <a class="{{ request()->routeIs('admin.inbox.*') ? 'active' : '' }}" href="{{ route('admin.inbox.index') }}">Inbox internal</a>

                <span class="sidebar-section-label">Komunikasi</span>
                <a class="{{ request()->routeIs('admin.whatsapp.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.dashboard') }}">WhatsApp & CRM</a>

                <span class="sidebar-section-label">Konten & bantuan</span>
                <a class="{{ request()->routeIs('admin.articles.*') ? 'active' : '' }}" href="{{ route('admin.articles.index') }}">Artikel</a>
                <a class="{{ request()->routeIs('admin.operations.*') && $operationModule === 'announcements' ? 'active' : '' }}" href="{{ route('admin.operations.index', 'announcements') }}">Pengumuman</a>
                <a class="{{ request()->routeIs('admin.operations.*') && $operationModule === 'materials' ? 'active' : '' }}" href="{{ route('admin.operations.index', 'materials') }}">Materi pemasaran</a>
                <a class="{{ (request()->routeIs('admin.operations.*') && $operationModule === 'tickets') || request()->routeIs('admin.tickets.*') ? 'active' : '' }}" href="{{ route('admin.operations.index', 'tickets') }}">Tiket bantuan</a>
                <a class="{{ request()->routeIs('admin.operations.*') && $operationModule === 'audit' ? 'active' : '' }}" href="{{ route('admin.operations.index', 'audit') }}">Audit log</a>

                <span class="sidebar-section-label">Pengaturan</span>
                <a class="{{ request()->routeIs('admin.features.*') ? 'active' : '' }}" href="{{ route('admin.features.edit') }}">Fitur aplikasi</a>
                <a class="{{ request()->routeIs('admin.mail.*') ? 'active' : '' }}" href="{{ route('admin.mail.edit') }}">Email & SMTP</a>
                <a class="{{ request()->routeIs('admin.branding.*') ? 'active' : '' }}" href="{{ route('admin.branding.edit') }}">Logo & branding</a>
                <a class="{{ request()->routeIs('admin.profile.*') ? 'active' : '' }}" href="{{ route('admin.profile.edit') }}">Profil admin</a>
            @else
                <span class="sidebar-section-label">Penjualan</span>
                <a class="{{ request()->routeIs('partner.prices.*') ? 'active' : '' }}" href="{{ route('partner.prices.index') }}">Harga mitra</a>
                <a class="{{ request()->routeIs('partner.invoices.*') ? 'active' : '' }}" href="{{ route('partner.invoices.index') }}">Invoice</a>
                <a class="{{ request()->routeIs('partner.operations.*') && $operationModule === 'commissions' ? 'active' : '' }}" href="{{ route('partner.operations.index', 'commissions') }}">Komisi saya</a>

                <span class="sidebar-section-label">Pengembangan</span>
                @if($featureFlags->enabled('partner_academy'))<a class="{{ request()->routeIs('partner.learning.*') ? 'active' : '' }}" href="{{ route('partner.learning.index') }}">Kelas saya</a>@endif
                @if($featureFlags->enabled('partner_community'))<a class="{{ request()->routeIs('partner.community.*') ? 'active' : '' }}" href="{{ route('partner.community.index') }}">Community</a>@endif
                @if($featureFlags->enabled('partner_inbox'))<a class="{{ request()->routeIs('partner.inbox.*') ? 'active' : '' }}" href="{{ route('partner.inbox.index') }}">Inbox</a>@endif
                <a class="{{ request()->routeIs('partner.operations.*') && $operationModule === 'announcements' ? 'active' : '' }}" href="{{ route('partner.operations.index', 'announcements') }}">Pengumuman</a>
                <a class="{{ request()->routeIs('partner.operations.*') && $operationModule === 'materials' ? 'active' : '' }}" href="{{ route('partner.operations.index', 'materials') }}">Materi pemasaran</a>
                <a class="{{ request()->routeIs('partner.operations.*') && $operationModule === 'tickets' ? 'active' : '' }}" href="{{ route('partner.operations.index', 'tickets') }}">Bantuan</a>

                <span class="sidebar-section-label">Akun</span>
                <a class="{{ request()->routeIs('partner.profile.*') ? 'active' : '' }}" href="{{ route('partner.profile.edit') }}">Profil</a>
            @endif
        </nav>

        <div class="admin-sidebar-footer">
            <a class="sidebar-site-link" href="{{ route('home') }}" target="_blank" rel="noopener">Lihat website ↗</a>
            <form action="{{ route($prefix.'.logout') }}" method="post">
                @csrf
                <button type="submit">Keluar</button>
            </form>
        </div>
    </aside>

    <main class="admin-main">
        <header class="admin-header">
            <div>
                <span>{{ $isAdmin ? 'Panel pengelola' : 'Portal kemitraan' }}</span>
                <h1>@yield('heading', $isAdmin ? 'Admin IzinHukum' : 'Mitra LegaOne')</h1>
            </div>
            <div class="admin-header-actions">@yield('header_action')</div>
        </header>

        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        @if(session('receipt_url'))
            <div class="alert alert-info"><a href="{{ session('receipt_url') }}" target="_blank">Buka kwitansi pembayaran ↗</a></div>
        @endif

        @if(session('activation_url'))
            <div class="alert alert-warning">
                <strong>Tautan aktivasi (berlaku 7 hari):</strong>
                <a href="{{ session('activation_url') }}" target="_blank">{{ session('activation_url') }}</a>
            </div>
        @endif

        @if($errors->any())
            <div class="alert alert-danger">
                <strong>Periksa kembali data:</strong>
                <ul class="mb-0 mt-2">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('content')
    </main>
</div>
@stack('scripts')
</body>
</html>

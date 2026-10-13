<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Portal') · IzinHukum</title>
    @vite(['resources/css/app.scss', 'resources/js/app.js'])
    <link rel="stylesheet" href="{{ asset('css/admin-fixes.css') }}?v=21.0.0">
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
                    <img class="brand-mark" src="{{ asset('brand/izinhukum-monogram.svg') }}" alt="" width="42" height="42">
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

            <a href="{{ route('admin.orders.index') }}">Pesanan</a>
            <a href="{{ route('admin.invoices.index') }}">Invoice & pembayaran</a>
            <a href="{{ route('admin.finance.index') }}">Keuangan</a>
            <a href="{{ route('admin.packages.index') }}">Paket & harga</a>
            <a href="{{ route('admin.service-landings.index') }}">Detail layanan</a>
            <a href="{{ route('admin.branding.edit') }}">Logo & identitas</a>
            <a href="{{ route('admin.profile.edit') }}">Profil admin</a>

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

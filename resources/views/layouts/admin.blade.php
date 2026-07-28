<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Portal') · IzinHukum</title>
    @vite(['resources/css/app.scss', 'resources/js/app.js'])
</head>
<body class="admin-body">
@php
    $isAdmin = $currentUser->isAdmin();
    $prefix = $isAdmin ? 'admin' : 'partner';
@endphp
<div class="admin-shell">
    <aside class="admin-sidebar">
        <a class="brand brand-light" href="{{ route($prefix.'.dashboard') }}">
            <span class="brand-mark">IH</span>
            <span class="brand-copy"><strong>IzinHukum</strong><small>{{ $isAdmin ? 'Administrator' : 'Mitra LegaOne' }}</small></span>
        </a>
        <div class="portal-identity">
            <strong>{{ $currentUser->name }}</strong>
            <small>{{ $currentUser->partner_code ?: $currentUser->email }}</small>
        </div>
        <nav>
            <a class="{{ request()->routeIs($prefix.'.dashboard') ? 'active' : '' }}" href="{{ route($prefix.'.dashboard') }}">Ringkasan</a>
            @if($isAdmin)
                <a class="{{ request()->routeIs('admin.inquiries.*') ? 'active' : '' }}" href="{{ route('admin.inquiries.index') }}">Permintaan masuk</a>
                <a class="{{ request()->routeIs('admin.packages.*') ? 'active' : '' }}" href="{{ route('admin.packages.index') }}">Harga & paket</a>
                <a class="{{ request()->routeIs('admin.partners.*') ? 'active' : '' }}" href="{{ route('admin.partners.index') }}">Mitra</a>
                <a class="{{ request()->routeIs('admin.academy.*') ? 'active' : '' }}" href="{{ route('admin.academy.index') }}">LMS Akademi</a>
            @else
                <a class="{{ request()->routeIs('partner.prices.*') ? 'active' : '' }}" href="{{ route('partner.prices.index') }}">Harga mitra</a>
                <a class="{{ request()->routeIs('partner.learning.*') ? 'active' : '' }}" href="{{ route('partner.learning.index') }}">Kelas saya</a>
            @endif
            <a class="{{ request()->routeIs($prefix.'.invoices.*') ? 'active' : '' }}" href="{{ route($prefix.'.invoices.index') }}">Invoice</a>
            @if($isAdmin)
                <a class="{{ request()->routeIs('admin.articles.*') ? 'active' : '' }}" href="{{ route('admin.articles.index') }}">Artikel</a>
                <a href="{{ route('admin.operations.index', 'announcements') }}">Pengumuman</a>
                <a href="{{ route('admin.operations.index', 'materials') }}">Materi pemasaran</a>
                <a href="{{ route('admin.operations.index', 'tickets') }}">Tiket bantuan</a>
                <a href="{{ route('admin.operations.index', 'commissions') }}">Komisi mitra</a>
                <a href="{{ route('admin.operations.index', 'audit') }}">Audit log</a>
                <a class="{{ request()->routeIs('admin.mail.*') ? 'active' : '' }}" href="{{ route('admin.mail.edit') }}">Email & SMTP</a>
            @else
                <a href="{{ route('partner.operations.index', 'announcements') }}">Pengumuman</a>
                <a href="{{ route('partner.operations.index', 'materials') }}">Materi pemasaran</a>
                <a href="{{ route('partner.operations.index', 'tickets') }}">Bantuan</a>
                <a href="{{ route('partner.operations.index', 'commissions') }}">Komisi saya</a>
            @endif
            <a class="{{ request()->routeIs($prefix.'.profile.*') ? 'active' : '' }}" href="{{ route($prefix.'.profile.edit') }}">Profil</a>
            <a href="{{ route('home') }}" target="_blank">Lihat website ↗</a>
        </nav>
        <form action="{{ route($prefix.'.logout') }}" method="post">
            @csrf
            <button type="submit">Keluar</button>
        </form>
    </aside>
    <main class="admin-main">
        <header class="admin-header">
            <div>
                <span>{{ $isAdmin ? 'Panel pengelola' : 'Portal kemitraan' }}</span>
                <h1>@yield('heading', $isAdmin ? 'Admin IzinHukum' : 'Mitra LegaOne')</h1>
            </div>
            @yield('header_action')
        </header>
        @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
        @if(session('activation_url'))
            <div class="alert alert-warning">
                <strong>Tautan aktivasi (berlaku 7 hari):</strong>
                <a href="{{ session('activation_url') }}" target="_blank">{{ session('activation_url') }}</a>
            </div>
        @endif
        @if($errors->any())
            <div class="alert alert-danger"><strong>Periksa kembali data:</strong><ul class="mb-0 mt-2">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        @yield('content')
    </main>
</div>
@stack('scripts')
</body>
</html>

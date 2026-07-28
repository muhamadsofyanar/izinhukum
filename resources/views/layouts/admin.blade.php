<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Admin') · IzinHukum</title>
    @vite(['resources/css/app.scss', 'resources/js/app.js'])
</head>
<body class="admin-body">
    <div class="admin-shell">
        <aside class="admin-sidebar">
            <a class="brand brand-light" href="{{ route('admin.dashboard') }}">
                <span class="brand-mark">IH</span>
                <span class="brand-copy"><strong>IzinHukum</strong><small>Admin</small></span>
            </a>
            <nav>
                <a class="{{ request()->routeIs('admin.dashboard') ? 'active' : '' }}" href="{{ route('admin.dashboard') }}">Ringkasan</a>
                <a class="{{ request()->routeIs('admin.inquiries.*') ? 'active' : '' }}" href="{{ route('admin.inquiries.index') }}">Permintaan masuk</a>
                <a class="{{ request()->routeIs('admin.packages.*') ? 'active' : '' }}" href="{{ route('admin.packages.index') }}">Harga & paket</a>
                <a href="{{ route('home') }}" target="_blank">Lihat website ↗</a>
            </nav>
            <form action="{{ route('admin.logout') }}" method="post">
                @csrf
                <button type="submit">Keluar</button>
            </form>
        </aside>
        <main class="admin-main">
            <header class="admin-header">
                <div><span>Panel pengelola</span><h1>@yield('heading', 'Admin IzinHukum')</h1></div>
            </header>
            @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
            @yield('content')
        </main>
    </div>
</body>
</html>

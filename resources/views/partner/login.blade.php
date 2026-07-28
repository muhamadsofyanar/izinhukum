<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk Mitra · IzinHukum</title>
    @vite(['resources/css/app.scss', 'resources/js/app.js'])
</head>
<body class="login-body">
<main class="login-card">
    <a class="brand mb-4" href="{{ route('home') }}">
        <span class="brand-mark">IH</span>
        <span class="brand-copy"><strong>IzinHukum</strong><small>Mitra LegaOne</small></span>
    </a>
    <h1>Portal mitra</h1>
    <p>Lihat harga mitra dan buat invoice untuk pelanggan Anda.</p>
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    <form action="{{ route('partner.login.store') }}" method="post">
        @csrf
        <div class="mb-3">
            <label class="form-label" for="email">Email mitra</label>
            <input class="form-control @error('email') is-invalid @enderror" id="email" name="email" type="email" value="{{ old('email') }}" required autofocus>
            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="mb-4">
            <label class="form-label" for="password">Kata sandi</label>
            <input class="form-control @error('password') is-invalid @enderror" id="password" name="password" type="password" required>
            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <button class="btn btn-primary w-100" type="submit">Masuk sebagai mitra</button>
    </form>
    <p class="text-center mt-4 mb-0"><a href="{{ route('partnership.create') }}">Belum menjadi mitra? Daftar</a></p>
</main>
</body>
</html>

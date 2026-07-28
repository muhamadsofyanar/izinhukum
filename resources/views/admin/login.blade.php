<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk Admin · IzinHukum</title>
    @vite(['resources/css/app.scss', 'resources/js/app.js'])
</head>
<body class="login-body">
    <main class="login-card">
        <a class="brand mb-4" href="{{ route('home') }}">
            <span class="brand-mark">IH</span>
            <span class="brand-copy"><strong>IzinHukum</strong><small>Panel admin</small></span>
        </a>
        <h1>Selamat datang kembali</h1>
        <p>Masuk untuk mengelola harga dan permintaan pelanggan.</p>
        <form action="{{ route('admin.login.store') }}" method="post">
            @csrf
            <div class="mb-3">
                <label class="form-label" for="email">Email admin</label>
                <input class="form-control @error('email') is-invalid @enderror" id="email" name="email" type="email" value="{{ old('email') }}" required autofocus>
                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-4">
                <label class="form-label" for="password">Kata sandi</label>
                <input class="form-control @error('password') is-invalid @enderror" id="password" name="password" type="password" required>
                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <button class="btn btn-primary w-100" type="submit">Masuk</button>
        </form>
    </main>
</body>
</html>

<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Aktivasi Mitra · IzinHukum</title>
    @vite(['resources/css/app.scss', 'resources/js/app.js'])
</head>
<body class="login-body">
<main class="login-card">
    <a class="brand mb-4" href="{{ route('home') }}">
        <span class="brand-mark">IH</span>
        <span class="brand-copy"><strong>IzinHukum</strong><small>Aktivasi Mitra LegaOne</small></span>
    </a>
    <h1>Buat kata sandi</h1>
    <p>Halo {{ $user->name }}, aktifkan akun {{ $user->partner_code }} untuk mulai menggunakan portal mitra.</p>
    <form action="{{ route('partner.activate.store', $token) }}" method="post">
        @csrf
        <div class="mb-3">
            <label class="form-label" for="password">Kata sandi baru</label>
            <input class="form-control @error('password') is-invalid @enderror" id="password" name="password" type="password" minlength="10" required>
            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="mb-4">
            <label class="form-label" for="password_confirmation">Ulangi kata sandi</label>
            <input class="form-control" id="password_confirmation" name="password_confirmation" type="password" minlength="10" required>
        </div>
        <button class="btn btn-primary w-100" type="submit">Aktifkan akun</button>
    </form>
</main>
</body>
</html>

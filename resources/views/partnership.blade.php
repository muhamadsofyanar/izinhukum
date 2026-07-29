@extends('layouts.app')

@section('title', 'Kemitraan LegaOne')
@section('meta_description', 'Bergabung sebagai Mitra LegaOne IzinHukum, akses harga mitra, batas harga jual, serta invoice profesional untuk pelanggan.')

@section('content')
<section class="partnership-hero">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-7">
                <span class="eyebrow eyebrow-light">Mitra LegaOne</span>
                <h1>Kembangkan layanan legalitas dengan sistem harga yang transparan.</h1>
                <p>Dapatkan akses harga mitra, batas harga jual yang jelas, katalog layanan, dan invoice profesional untuk pelanggan Anda.</p>
                <a class="btn btn-light btn-lg" href="#daftar-mitra">Daftar sebagai mitra</a>
            </div>
            <div class="col-lg-5">
                <div class="partner-benefit-card">
                    <div><span>01</span><strong>Harga khusus mitra</strong><small>Modal layanan terlihat jelas di portal.</small></div>
                    <div><span>02</span><strong>Margin terukur</strong><small>Batas minimum jual mencegah perang harga.</small></div>
                    <div><span>03</span><strong>Invoice siap kirim</strong><small>Kirim email atau simpan sebagai PDF.</small></div>
                </div>
            </div>
        </div>
    </div>
</section>
<section class="section">
    <div class="container">
        <div class="section-heading"><span class="eyebrow">Cara bekerja</span><h2>Satu portal untuk penawaran dan tagihan</h2><p>Mitra tetap mengelola hubungan dengan pelanggan, sementara IzinHukum membantu proses layanan sesuai ruang lingkup yang disepakati.</p></div>
        <div class="row g-4">
            <div class="col-md-4"><article class="value-card"><span>01</span><h3>Pilih layanan</h3><p>Lihat harga website, batas minimum end user, dan harga mitra.</p></article></div>
            <div class="col-md-4"><article class="value-card"><span>02</span><h3>Buat invoice</h3><p>Gunakan harga jual yang sesuai tanpa membuka harga modal kepada pelanggan.</p></article></div>
            <div class="col-md-4"><article class="value-card"><span>03</span><h3>Pantau status</h3><p>Kelola draf, invoice terkirim, pembayaran, dan tagihan dari IzinHukum.</p></article></div>
        </div>
    </div>
</section>
<section class="section section-soft" id="paket-mitra">
    <div class="container">
        <div class="section-heading">
            <span class="eyebrow">Pilihan keanggotaan</span>
            <h2>Tiga paket. Pilih sesuai cara Anda memasarkan.</h2>
            <p>Semua paket memiliki kode referral. Perbedaannya terletak pada tingkat komisi dan dukungan.</p>
        </div>
        <div class="row g-4">
            @foreach($plans as $code => $plan)
                <div class="col-lg-4">
                    <article class="value-card h-100 d-flex flex-column {{ $plan['recommended'] ? 'border border-primary' : '' }}">
                        @if($plan['recommended'])
                            <span class="status status-paid align-self-start mb-3">Paling sesuai untuk mitra aktif</span>
                        @endif
                        <small>{{ strtoupper($plan['name']) }}</small>
                        <h3 class="mt-2">{{ $plan['annual_price'] > 0 ? 'Rp'.number_format($plan['annual_price'], 0, ',', '.') : 'Rp0' }}</h3>
                        <p class="text-muted">per tahun</p>
                        <p>{{ $plan['description'] }}</p>
                        <ul class="check-list flex-grow-1">
                            @foreach($plan['features'] as $feature)
                                <li>{{ $feature }}</li>
                            @endforeach
                        </ul>
                        <a class="btn {{ $plan['recommended'] ? 'btn-primary' : 'btn-outline-primary' }} choose-partner-plan"
                           href="#daftar-mitra"
                           data-plan="{{ $code }}">
                            Pilih {{ $plan['name'] }}
                        </a>
                    </article>
                </div>
            @endforeach
        </div>
        <p class="text-center text-muted small mt-4">Komisi dihitung dari pembayaran aktif. Pembayaran yang dibatalkan tidak menghasilkan komisi.</p>
    </div>
</section>
<section class="section section-soft" id="daftar-mitra">
    <div class="container">
        <div class="row g-5">
            <div class="col-lg-5"><span class="eyebrow">Pendaftaran mitra</span><h2>Bergabung dengan LegaOne</h2><p>Isi profil singkat dan pilih paket. Tim akan meninjau pendaftaran sebelum akun diaktifkan.</p><div class="admin-note">Paket Gratis tidak dikenai biaya. Biaya paket Berbayar atau Prioritas dikonfirmasi oleh admin sebelum aktivasi level.</div></div>
            <div class="col-lg-7">
                <form class="form-card" action="{{ route('partnership.store') }}" method="post">
                    @csrf
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Paket mitra *</label>
                            <div class="row g-2">
                                @foreach($plans as $code => $plan)
                                    <div class="col-md-4">
                                        <label class="form-check border rounded p-3 h-100">
                                            <input class="form-check-input partner-plan-radio" type="radio" name="desired_partner_level" value="{{ $code }}" @checked(old('desired_partner_level') === $code) required>
                                            <span class="form-check-label d-block ms-1">
                                                <strong>{{ $plan['name'] }}</strong>
                                                <small class="d-block">{{ $plan['annual_price'] > 0 ? 'Rp'.number_format($plan['annual_price'], 0, ',', '.').'/tahun' : 'Rp0/tahun' }}</small>
                                            </span>
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                            @error('desired_partner_level')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6"><label class="form-label">Nama lengkap *</label><input class="form-control @error('name') is-invalid @enderror" name="name" value="{{ old('name') }}" required>@error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="col-md-6"><label class="form-label">Email *</label><input class="form-control @error('email') is-invalid @enderror" name="email" type="email" value="{{ old('email') }}" required>@error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="col-md-6"><label class="form-label">Password mitra *</label><input class="form-control @error('password') is-invalid @enderror" name="password" type="password" minlength="10" autocomplete="new-password" required>@error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="col-md-6"><label class="form-label">Ulangi password *</label><input class="form-control" name="password_confirmation" type="password" minlength="10" autocomplete="new-password" required></div>
                        <div class="col-md-6"><label class="form-label">WhatsApp *</label><input class="form-control @error('phone') is-invalid @enderror" name="phone" value="{{ old('phone') }}" required>@error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="col-md-6"><label class="form-label">Kota/kabupaten *</label><input class="form-control @error('city') is-invalid @enderror" name="city" value="{{ old('city') }}" required>@error('city')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="col-md-6"><label class="form-label">Perusahaan/organisasi</label><input class="form-control" name="company_name" value="{{ old('company_name') }}"></div>
                        <div class="col-md-6"><label class="form-label">NPWP</label><input class="form-control" name="tax_id" value="{{ old('tax_id') }}"></div>
                        <div class="col-12"><label class="form-label">Alamat</label><textarea class="form-control" name="address" rows="2">{{ old('address') }}</textarea></div>
                        <div class="col-12"><label class="form-label">Ceritakan rencana kemitraan</label><textarea class="form-control" name="message" rows="4">{{ old('message') }}</textarea></div>
                        <div class="col-12"><label class="consent-line"><input type="checkbox" name="privacy_consent" value="1" required> Saya menyetujui pemrosesan data sesuai <a href="{{ route('legal.privacy') }}" target="_blank">Kebijakan Privasi</a>.</label></div>
                    </div>
                    <button class="btn btn-primary mt-4" type="submit">Kirim pendaftaran</button>
                </form>
            </div>
        </div>
    </div>
</section>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.choose-partner-plan').forEach(button => {
        button.addEventListener('click', () => {
            const radio = document.querySelector(`.partner-plan-radio[value="${button.dataset.plan}"]`);
            if (radio) radio.checked = true;
        });
    });
});
</script>
@endpush

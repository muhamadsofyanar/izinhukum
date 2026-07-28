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
<section class="section section-soft" id="daftar-mitra">
    <div class="container">
        <div class="row g-5">
            <div class="col-lg-5"><span class="eyebrow">Pendaftaran mitra</span><h2>Bergabung dengan LegaOne</h2><p>Isi profil singkat. Tim akan meninjau pendaftaran dan mengirim tautan aktivasi akun jika disetujui.</p><div class="admin-note">Tidak ada biaya pendaftaran yang dipungut melalui formulir ini.</div></div>
            <div class="col-lg-7">
                <form class="form-card" action="{{ route('partnership.store') }}" method="post">
                    @csrf
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Nama lengkap *</label><input class="form-control @error('name') is-invalid @enderror" name="name" value="{{ old('name') }}" required>@error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="col-md-6"><label class="form-label">Email *</label><input class="form-control @error('email') is-invalid @enderror" name="email" type="email" value="{{ old('email') }}" required>@error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
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

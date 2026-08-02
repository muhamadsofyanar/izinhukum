@extends('layouts.app')

@section('title', 'Konsultasi Legalitas dan Lanjut Deal via WhatsApp')
@section('meta_description', 'Isi kebutuhan legalitas di IzinHukum, dapatkan nomor referensi, lalu lanjutkan konsultasi dan deal secara langsung melalui WhatsApp.')

@section('content')
<section class="hero hero-conversion">
    <div class="hero-orb hero-orb-one"></div>
    <div class="hero-orb hero-orb-two"></div>
    <div class="container position-relative">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="eyebrow">Dari website, lanjut langsung ke WhatsApp</span>
                <h1>Isi kebutuhan sekali. <span>Lanjutkan deal di WhatsApp.</span></h1>
                <p class="hero-copy">Pilih layanan dan ceritakan kebutuhan Anda. Sistem mencatat permintaan serta membuat nomor referensi, kemudian WhatsApp terbuka agar pembahasan tidak dimulai dari nol.</p>
                <div class="d-flex flex-wrap gap-3">
                    @if($proposalEnabled)<a class="btn btn-primary btn-lg" href="#home-lead-form">Mulai konsultasi gratis</a>@endif
                    <a class="btn btn-ghost btn-lg" href="{{ route('services.index') }}">Lihat layanan <span>→</span></a>
                </div>
                <div class="trust-row">
                    <div><strong>±1 menit</strong><span>Isi form singkat</span></div>
                    <div><strong>Langsung tercatat</strong><span>Dapat nomor referensi</span></div>
                    <div><strong>Tetap personal</strong><span>Deal manual via WA</span></div>
                </div>
            </div>
            <div class="col-lg-6">
                @if($proposalEnabled)
                <form class="home-lead-card" id="home-lead-form" action="{{ route('proposal.store') }}" method="post">
                    @csrf
                    <input type="hidden" name="journey_source" value="website">
                    <div class="home-lead-head"><div><span>Mulai di sini</span><h2>Kebutuhan legalitas Anda</h2></div><span class="live-dot">Siap ditindaklanjuti</span></div>
                    @if($errors->any())<div class="alert alert-danger py-2">Periksa kembali data yang ditandai.</div>@endif
                    <label class="field"><span>Pilih layanan</span><select class="form-select @error('service_package_id') is-invalid @enderror" name="service_package_id"><option value="">Belum tahu / konsultasi dahulu</option>@foreach($packages->groupBy(fn($package) => $package->service?->name) as $serviceName=>$servicePackages)<optgroup label="{{ $serviceName }}">@foreach($servicePackages as $package)<option value="{{ $package->id }}" @selected(old('service_package_id') == $package->id)>{{ $package->name }} · {{ $package->price === 0 && $package->is_estimated ? 'Sesuai kebutuhan' : $package->formattedPrice() }}</option>@endforeach</optgroup>@endforeach</select>@error('service_package_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</label>
                    <div class="row g-2">
                        <label class="field col-md-6"><span>Nama lengkap *</span><input class="form-control @error('name') is-invalid @enderror" name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="name">@error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror</label>
                        <label class="field col-md-6"><span>Nomor WhatsApp *</span><input class="form-control @error('phone') is-invalid @enderror" name="phone" value="{{ old('phone') }}" required maxlength="32" inputmode="tel" autocomplete="tel">@error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror</label>
                        <label class="field col-md-7"><span>Nama usaha/perusahaan</span><input class="form-control @error('company_name') is-invalid @enderror" name="company_name" value="{{ old('company_name') }}" maxlength="160">@error('company_name')<div class="invalid-feedback">{{ $message }}</div>@enderror</label>
                        <label class="field col-md-5"><span>Kode kupon</span><input class="form-control text-uppercase @error('coupon_code') is-invalid @enderror" name="coupon_code" value="{{ old('coupon_code') }}" maxlength="32" placeholder="Jika ada">@error('coupon_code')<div class="invalid-feedback">{{ $message }}</div>@enderror</label>
                        <label class="field col-12"><span>Ceritakan kebutuhan</span><textarea class="form-control @error('message') is-invalid @enderror" name="message" rows="2" maxlength="3000" placeholder="Contoh: ingin mendirikan PT dan membutuhkan NIB.">{{ old('message') }}</textarea>@error('message')<div class="invalid-feedback">{{ $message }}</div>@enderror</label>
                    </div>
                    <label class="consent-line home-consent"><input type="checkbox" name="privacy_consent" value="1" required @checked(old('privacy_consent'))> Saya menyetujui pemrosesan data untuk tindak lanjut sesuai <a href="{{ route('legal.privacy') }}" target="_blank">Kebijakan Privasi</a>.</label>
                    @error('privacy_consent')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    <button class="btn btn-primary btn-lg w-100 mt-3" type="submit">Kirim & lanjut ke WhatsApp →</button>
                    <small class="home-lead-safe">Belum ada pembayaran. Pesan WhatsApp tetap Anda kirim sendiri.</small>
                </form>
                @else
                <div class="hero-card"><div class="hero-card-head"><span>Alur layanan IzinHukum</span><span class="live-dot">Siap membantu</span></div><ol class="process-list"><li><span>1</span><div><strong>Ceritakan kebutuhan</strong><small>Pilih layanan atau konsultasikan situasi Anda.</small></div></li><li><span>2</span><div><strong>Terima arahan & penawaran</strong><small>Ruang lingkup, dokumen, biaya, dan estimasi dijelaskan.</small></div></li><li><span>3</span><div><strong>Kami proses sampai selesai</strong><small>Anda menerima pembaruan dan dokumen hasil.</small></div></li></ol></div>
                @endif
            </div>
        </div>
    </div>
</section>

<section class="proof-strip">
    <div class="container">
        <div class="proof-grid">
            <span>Form langsung di halaman utama</span>
            <span>Nomor referensi otomatis</span>
            <span>Lanjut ke WhatsApp</span>
            <span>Progres dapat dilacak</span>
        </div>
    </div>
</section>

<section class="home-conversion-section">
    <div class="container">
        <div class="home-conversion-head"><span>Alur baru IzinHukum</span><h2>Dari iklan hingga pembahasan deal, tanpa kehilangan data lead</h2></div>
        <div class="home-conversion-grid">
            <article><span>01</span><strong>Buka website</strong><small>Calon klien datang dari broadcast, referral, atau pencarian.</small></article>
            <article><span>02</span><strong>Isi kebutuhan</strong><small>Layanan dan konteks dicatat sebelum percakapan dimulai.</small></article>
            <article><span>03</span><strong>Lanjut WhatsApp</strong><small>Nomor referensi ikut dibawa untuk pembahasan manual.</small></article>
            <article><span>04</span><strong>Penawaran & proses</strong><small>Admin menindaklanjuti melalui pipeline sampai selesai.</small></article>
        </div>
    </div>
</section>

<section class="section section-soft">
    <div class="container">
        <div class="section-heading section-heading-split">
            <div>
                <span class="eyebrow">Coba sebelum memesan</span>
                <h2>Rasakan alur legalitas secara langsung</h2>
                <p>Siapkan nama, struktur awal dokumen, dan kandidat KBLI. Hasil yang dipilih dapat diteruskan ke proposal dan tercatat sebagai order.</p>
            </div>
            <a class="text-link" href="{{ route('tools.index') }}">Lihat semua alat →</a>
        </div>
        <div class="tool-grid tool-grid-home">
            <a class="tool-card" href="{{ route('tools.name-generator') }}"><span class="tool-card-number">01</span><small>Identitas</small><h3>Generator nama</h3><p>PT, PT PMA, Perseroan Perorangan, CV, Firma, Persekutuan Perdata, Yayasan, Perkumpulan, dan Koperasi.</p><strong>Coba →</strong></a>
            <a class="tool-card" href="{{ route('tools.deed-simulator') }}"><span class="tool-card-number">02</span><small>Dokumen</small><h3>Simulasi bahan akta</h3><p>Lihat ringkasan kedudukan, kegiatan, pendiri, modal, dan organ.</p><strong>Mulai →</strong></a>
            <a class="tool-card" href="{{ route('kbli.index') }}"><span class="tool-card-number">03</span><small>Perizinan</small><h3>Cek KBLI & risiko</h3><p>Cari kegiatan usaha, tingkat risiko, izin, persyaratan, dan kewajiban.</p><strong>Cari →</strong></a>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="section-heading section-heading-split">
            <div>
                <span class="eyebrow">Layanan pilihan</span>
                <h2>Legalitas yang sesuai dengan tahap bisnis Anda</h2>
            </div>
            <a class="text-link" href="{{ route('services.index') }}">Lihat semua layanan →</a>
        </div>

        <div class="row g-4">
            @foreach($featuredServices as $service)
                @php($lowest = $service->packages->min('price'))
                <div class="col-md-6 col-lg-4">
                    <a class="service-card" href="{{ route('services.show', $service) }}">
                        <span class="service-icon">{{ mb_substr($service->short_name, 0, 2) }}</span>
                        <span class="service-category">{{ $service->category }}</span>
                        <h3>{{ $service->name }}</h3>
                        <p>{{ $service->summary }}</p>
                        <div class="service-card-bottom">
                            <span>@if((int) $lowest === 0) Harga <strong>berdasarkan penawaran</strong>@else Mulai <strong>Rp{{ number_format($lowest, 0, ',', '.') }}</strong>@endif</span>
                            <span class="arrow">→</span>
                        </div>
                    </a>
                </div>
            @endforeach
        </div>
    </div>
</section>

<section class="section section-soft">
    <div class="container">
        <div class="section-heading text-center mx-auto">
            <span class="eyebrow">Kenapa IzinHukum</span>
            <h2>Lebih tenang mengurus dokumen penting</h2>
            <p>Kami menyederhanakan proses legalitas tanpa menghilangkan ketelitian yang dibutuhkan.</p>
        </div>
        <div class="row g-4">
            <div class="col-md-4">
                <article class="value-card"><span>01</span><h3>Praktis dari mana saja</h3><p>Konsultasi, pengiriman dokumen, dan pemantauan proses dapat dilakukan jarak jauh.</p></article>
            </div>
            <div class="col-md-4">
                <article class="value-card"><span>02</span><h3>Ruang lingkup jelas</h3><p>Anda mengetahui dokumen, tahapan, biaya, dan batas layanan sebelum pekerjaan dimulai.</p></article>
            </div>
            <div class="col-md-4">
                <article class="value-card"><span>03</span><h3>Didampingi sampai selesai</h3><p>Tim membantu menindaklanjuti proses dan menjelaskan hal yang perlu Anda lakukan.</p></article>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="kbli-promo">
            <div>
                <span class="eyebrow eyebrow-light">Cek KBLI</span>
                <h2>Pilih kegiatan usaha dengan lebih tepat</h2>
                <p>Cari kode berdasarkan nomor atau kata kunci, lalu konsultasikan kesesuaiannya sebelum pengajuan OSS.</p>
                <form class="kbli-inline" action="{{ route('kbli.index') }}" method="get">
                    <label class="visually-hidden" for="home-kbli">Cari kode atau kegiatan usaha</label>
                    <input id="home-kbli" name="q" type="search" placeholder="Contoh: restoran, konsultasi, 62010" minlength="2">
                    <button class="btn btn-light" type="submit">Cari KBLI</button>
                </form>
            </div>
            <div class="kbli-visual" aria-hidden="true">
                <div><span>62010</span><strong>Aktivitas Pemrograman Komputer</strong><small>Risiko rendah</small></div>
                <div><span>46900</span><strong>Perdagangan Besar</strong><small>Menengah rendah</small></div>
                <div><span>70209</span><strong>Konsultasi Manajemen</strong><small>Risiko rendah</small></div>
            </div>
        </div>
    </div>
</section>

<section class="section pt-0">
    <div class="container">
        <div class="section-heading">
            <span class="eyebrow">Harga transparan</span>
            <h2>Paket yang paling sering dipilih</h2>
            <p>Nominal berlabel oranye adalah harga perkiraan dan akan dikonfirmasi setelah konsultasi.</p>
        </div>
        <div class="row g-4">
            @foreach($services->flatMap->packages->where('is_popular', true)->take(3) as $package)
                <div class="col-lg-4">
                    <x-price-card :package="$package" />
                </div>
            @endforeach
        </div>
    </div>
</section>
@endsection

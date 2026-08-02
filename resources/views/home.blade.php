@extends('layouts.app')

@section('title', 'Jasa Legalitas Bisnis Sampai Tuntas')
@section('meta_description', 'IzinHukum membantu pendirian PT, CV, Firma, Yayasan, Perkumpulan, OSS, NIB, Virtual Office, merek, dan layanan hukum lainnya.')

@section('content')
<section class="hero">
    <div class="hero-orb hero-orb-one"></div>
    <div class="hero-orb hero-orb-two"></div>
    <div class="container position-relative">
        <div class="row align-items-center g-5">
            <div class="col-lg-7">
                <span class="eyebrow">Konsultan legal untuk bisnis Indonesia</span>
                <h1>Urus legalitas lebih pasti. <span>Kami bantu sampai tuntas.</span></h1>
                <p class="hero-copy">Tak perlu mondar-mandir atau bingung membaca prosedur. Mulai dari konsultasi, dokumen, hingga perizinan—Anda tinggal memantau prosesnya.</p>
                <div class="d-flex flex-wrap gap-3">
                    <a class="btn btn-primary btn-lg" href="{{ route('proposal.create') }}">Konsultasi gratis</a>
                    <a class="btn btn-ghost btn-lg" href="{{ route('services.index') }}">Lihat layanan <span>→</span></a>
                </div>
                <div class="trust-row">
                    <div><strong>Praktis</strong><span>Satu pintu</span></div>
                    <div><strong>Transparan</strong><span>Harga jelas</span></div>
                    <div><strong>Nasional</strong><span>Seluruh Indonesia</span></div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="hero-card">
                    <div class="hero-card-head">
                        <span>Alur layanan IzinHukum</span>
                        <span class="live-dot">Siap membantu</span>
                    </div>
                    <ol class="process-list">
                        <li><span>1</span><div><strong>Ceritakan kebutuhan</strong><small>Pilih layanan atau konsultasikan situasi Anda.</small></div></li>
                        <li><span>2</span><div><strong>Terima arahan & penawaran</strong><small>Ruang lingkup, dokumen, biaya, dan estimasi proses dijelaskan.</small></div></li>
                        <li><span>3</span><div><strong>Kami proses sampai selesai</strong><small>Anda menerima pembaruan dan dokumen hasil.</small></div></li>
                    </ol>
                    <div class="hero-card-foot">
                        <span class="avatar-stack"><i>IH</i><i>CS</i><i>LG</i></span>
                        <span>Tim legal & customer support</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="proof-strip">
    <div class="container">
        <div class="proof-grid">
            <span>Dokumen lebih terarah</span>
            <span>Proses dapat dipantau</span>
            <span>Konsultasi jarak jauh</span>
            <span>Biaya transparan</span>
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

@extends('layouts.app')

@section('title', 'Cek Risiko KBLI')
@section('meta_description', 'Cari kode KBLI berdasarkan nomor atau kata kunci kegiatan usaha sebagai langkah awal menentukan perizinan OSS.')

@section('content')
<section class="page-hero page-hero-search">
    <div class="container">
        <span class="eyebrow">Pencarian kegiatan usaha</span>
        <h1>Cek kode dan risiko KBLI</h1>
        <p>Gunakan nomor atau kata kunci kegiatan usaha. Hasil ini adalah panduan awal dan perlu diverifikasi sebelum pengajuan.</p>
        <form class="search-panel" method="get" action="{{ route('kbli.index') }}">
            <label class="visually-hidden" for="kbli-search">Cari KBLI</label>
            <input id="kbli-search" type="search" name="q" value="{{ $term }}" minlength="2" placeholder="Cari kode atau kegiatan usaha, misalnya restoran..." autofocus>
            <button class="btn btn-primary" type="submit">Cari</button>
        </form>
    </div>
</section>

<section class="section">
    <div class="container">
        @if($term !== '' && mb_strlen($term) < 2)
            <div class="empty-state"><h2>Kata kunci terlalu pendek</h2><p>Masukkan minimal dua karakter.</p></div>
        @elseif($term !== '' && $results->isEmpty())
            <div class="empty-state"><h2>Belum ada hasil untuk “{{ $term }}”</h2><p>Coba kata yang lebih umum atau konsultasikan kegiatan usaha Anda.</p><a class="btn btn-primary" href="{{ route('proposal.create') }}">Konsultasi KBLI</a></div>
        @elseif($results->isNotEmpty())
            <div class="result-heading">
                <div><span class="eyebrow">Hasil pencarian</span><h2>{{ $results->count() }} kode ditemukan</h2></div>
                <span class="data-notice">Data awal · wajib diverifikasi</span>
            </div>
            <div class="kbli-results">
                @foreach($results as $kbli)
                    <article class="kbli-result">
                        <div class="kbli-code">{{ $kbli->code }}</div>
                        <div class="kbli-main">
                            <h3>{{ $kbli->title }}</h3>
                            <p>{{ $kbli->description }}</p>
                            <div class="kbli-meta">
                                <span>Risiko: <strong>{{ $kbli->risk_level ?: 'Perlu verifikasi' }}</strong></span>
                                <span>Perizinan: <strong>{{ $kbli->licensing ?: 'Perlu verifikasi' }}</strong></span>
                            </div>
                        </div>
                        <a href="{{ route('proposal.create') }}">Konsultasikan →</a>
                    </article>
                @endforeach
            </div>
            <div class="legal-notice">
                <strong>Catatan penting</strong>
                <p>Data KBLI dalam versi awal ini belum merupakan basis data lengkap. Tingkat risiko dan perizinan dapat dipengaruhi skala, lokasi, produk, serta ketentuan sektoral. Verifikasi melalui OSS dan peraturan terbaru sebelum pengajuan.</p>
            </div>
        @else
            <div class="empty-state empty-state-intro">
                <span class="empty-icon">KBLI</span>
                <h2>Mulai dengan kegiatan utama bisnis Anda</h2>
                <p>Contoh pencarian: <a href="{{ route('kbli.index', ['q' => 'restoran']) }}">restoran</a>, <a href="{{ route('kbli.index', ['q' => 'konsultasi']) }}">konsultasi</a>, atau <a href="{{ route('kbli.index', ['q' => '62010']) }}">62010</a>.</p>
            </div>
        @endif
    </div>
</section>
@endsection

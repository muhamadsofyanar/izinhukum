@extends('layouts.app')

@section('title', 'Cek Risiko KBLI 2025')
@section('meta_description', 'Cari 1.559 kode KBLI 2025 dan periksa tingkat risiko, skala usaha, perizinan, persyaratan, kewajiban, serta kewenangan berdasarkan data OSS-RBA.')

@section('content')
<section class="page-hero page-hero-search">
    <div class="container">
        <span class="eyebrow">KBLI 2025 · OSS berbasis risiko</span>
        <h1>Cek kode dan risiko kegiatan usaha</h1>
        <p>Cari berdasarkan kode, judul, atau uraian kegiatan. Detail risiko ditampilkan per ruang lingkup dan skala usaha karena satu kode KBLI dapat memiliki lebih dari satu ketentuan.</p>
        <form class="search-panel" method="get" action="{{ route('kbli.index') }}" role="search">
            <label class="visually-hidden" for="kbli-search">Cari KBLI 2025</label>
            <input id="kbli-search" type="search" name="q" value="{{ $term }}" minlength="2" maxlength="100" placeholder="Contoh: restoran, perdagangan, 62010..." autofocus>
            <button class="btn btn-primary" type="submit">Cari KBLI</button>
        </form>
        <div class="kbli-source-line">
            <span>1.559 kode resmi</span>
            <span>Peraturan BPS No. 7 Tahun 2025</span>
            <span>Risiko: OSS-RBA / PP No. 28 Tahun 2025</span>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        @if($term !== '' && mb_strlen($term) < 2)
            <div class="empty-state">
                <h2>Kata kunci terlalu pendek</h2>
                <p>Masukkan minimal dua karakter.</p>
            </div>
        @elseif($results && $results->total() === 0)
            <div class="empty-state">
                <h2>Belum ada hasil untuk “{{ $term }}”</h2>
                <p>Coba istilah kegiatan yang lebih umum atau konsultasikan kegiatan utama usaha Anda.</p>
                <a class="btn btn-primary" href="{{ route('proposal.create') }}">Konsultasi KBLI</a>
            </div>
        @elseif($results && $results->total() > 0)
            <div class="result-heading">
                <div>
                    <span class="eyebrow">Hasil pencarian</span>
                    <h2>{{ number_format($results->total(), 0, ',', '.') }} kode ditemukan</h2>
                </div>
                <span class="data-notice">Versi KBLI 2025</span>
            </div>

            <div class="kbli-results">
                @foreach($results as $kbli)
                    <article class="kbli-result">
                        <div class="kbli-code">
                            <small>KBLI 2025</small>
                            <strong>{{ $kbli->code }}</strong>
                        </div>
                        <div class="kbli-main">
                            <div class="kbli-category">{{ $kbli->category_code }} · {{ $kbli->category_title }}</div>
                            <h3>{{ $kbli->title }}</h3>
                            <p>{{ \Illuminate\Support\Str::limit($kbli->description, 210) }}</p>
                            <div class="kbli-meta">
                                <span>
                                    Tingkat risiko:
                                    @forelse($kbli->risk_levels ?? [] as $risk)
                                        <strong>{{ $risk }}{{ $loop->last ? '' : ', ' }}</strong>
                                    @empty
                                        <strong>Belum dicantumkan pada OSS</strong>
                                    @endforelse
                                </span>
                                <span>{{ $kbli->scopes_count }} ruang lingkup</span>
                            </div>
                        </div>
                        <a class="kbli-detail-link" href="{{ route('kbli.show', $kbli->code) }}">Lihat detail <span aria-hidden="true">→</span></a>
                    </article>
                @endforeach
            </div>

            <div class="kbli-pagination">
                {{ $results->links('pagination::bootstrap-5') }}
            </div>

            <div class="legal-notice">
                <strong>Catatan penggunaan</strong>
                <p>Hasil ini merupakan informasi awal. Penetapan akhir dapat dipengaruhi ruang lingkup, skala usaha, lokasi, luas lahan, produk, persyaratan dasar, dan ketentuan sektoral. Pastikan kembali pada sistem OSS sebelum mengajukan perizinan.</p>
            </div>
        @else
            <div class="empty-state empty-state-intro">
                <span class="empty-icon">2025</span>
                <h2>Mulai dari kegiatan utama bisnis Anda</h2>
                <p>Contoh pencarian: <a href="{{ route('kbli.index', ['q' => 'restoran']) }}">restoran</a>, <a href="{{ route('kbli.index', ['q' => 'konsultasi']) }}">konsultasi</a>, atau <a href="{{ route('kbli.index', ['q' => '62199']) }}">62199</a>.</p>
            </div>
        @endif
    </div>
</section>
@endsection

@extends('layouts.app')

@section('title', "KBLI {$kbli->code} - {$kbli->title}")
@section('meta_description', \Illuminate\Support\Str::limit("Cek risiko dan perizinan KBLI 2025 {$kbli->code} {$kbli->title}. {$kbli->description}", 155))

@section('content')
<section class="page-hero page-hero-compact kbli-detail-hero">
    <div class="container">
        <nav class="breadcrumb-nav" aria-label="Breadcrumb">
            <a href="{{ route('home') }}">Beranda</a>
            <span>/</span>
            <a href="{{ route('kbli.index') }}">Cek Risiko KBLI</a>
            <span>/</span>
            <span>{{ $kbli->code }}</span>
        </nav>

        <div class="kbli-detail-title">
            <div class="kbli-code kbli-code-large">
                <small>KBLI 2025</small>
                <strong>{{ $kbli->code }}</strong>
            </div>
            <div>
                <span class="eyebrow">{{ $kbli->category_code }} · {{ $kbli->category_title }}</span>
                <h1>{{ $kbli->title }}</h1>
            </div>
        </div>
    </div>
</section>

<section class="section kbli-detail-section">
    <div class="container">
        <div class="kbli-detail-grid">
            <main>
                <article class="kbli-description-card">
                    <span class="detail-label">Uraian kegiatan</span>
                    <p>{!! nl2br(e($kbli->description)) !!}</p>
                </article>

                <div class="kbli-scope-heading">
                    <div>
                        <span class="eyebrow">Profil perizinan</span>
                        <h2>Ruang lingkup dan tingkat risiko</h2>
                    </div>
                    <span>{{ $kbli->scopes->count() }} ruang lingkup</span>
                </div>

                <div class="kbli-scopes">
                    @forelse($kbli->scopes as $scope)
                        <details class="kbli-scope" @if($loop->first) open @endif>
                            <summary>
                                <div>
                                    <strong>{{ $scope->name }}</strong>
                                    @if($scope->sector)
                                        <span>{{ $scope->sector }}</span>
                                    @endif
                                </div>
                                <small>{{ $scope->profiles->count() }} profil skala</small>
                            </summary>

                            <div class="kbli-scope-content">
                                @if(!empty($scope->regulations))
                                    <div class="scope-regulations">
                                        <strong>Dasar pengaturan</strong>
                                        <ul>
                                            @foreach($scope->regulations as $regulation)
                                                <li>{{ $regulation }}</li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif

                                <div class="risk-profile-list">
                                    @foreach($scope->profiles as $profile)
                                        @php
                                            $risk = mb_strtolower($profile->risk_level);
                                            $riskClass = str_contains($risk, 'menengah tinggi')
                                                ? 'risk-medium-high'
                                                : (str_contains($risk, 'menengah rendah')
                                                    ? 'risk-medium-low'
                                                    : (str_contains($risk, 'tinggi')
                                                        ? 'risk-high'
                                                        : (str_contains($risk, 'rendah') ? 'risk-low' : 'risk-neutral')));
                                        @endphp

                                        <article class="risk-profile">
                                            <header>
                                                <div>
                                                    <span>Skala usaha</span>
                                                    <strong>{{ $profile->business_scale }}</strong>
                                                </div>
                                                <span class="risk-badge {{ $riskClass }}">{{ $profile->risk_level }}</span>
                                            </header>

                                            <div class="risk-facts">
                                                <div>
                                                    <span>Perizinan berusaha</span>
                                                    <strong>{{ implode(', ', $profile->licenses ?? []) ?: 'Tidak dicantumkan' }}</strong>
                                                </div>
                                                <div>
                                                    <span>Jangka waktu</span>
                                                    <strong>{{ $profile->issue_period ?: 'Tidak dicantumkan' }}</strong>
                                                </div>
                                                <div>
                                                    <span>Luas lahan</span>
                                                    <strong>{{ $profile->land_area ?: 'Tidak diatur' }}</strong>
                                                </div>
                                            </div>

                                            <div class="risk-detail-groups">
                                                <details>
                                                    <summary>Persyaratan <span>{{ count($profile->requirements ?? []) }}</span></summary>
                                                    @forelse($profile->requirements ?? [] as $requirement)
                                                        <div class="risk-list-item">
                                                            <p>{{ $requirement['text'] }}</p>
                                                            @if($requirement['period'])
                                                                <small>{{ $requirement['period'] }}</small>
                                                            @endif
                                                        </div>
                                                    @empty
                                                        <p class="risk-empty">Tidak ada persyaratan yang dicantumkan.</p>
                                                    @endforelse
                                                </details>

                                                <details>
                                                    <summary>Kewajiban <span>{{ count($profile->obligations ?? []) }}</span></summary>
                                                    @forelse($profile->obligations ?? [] as $obligation)
                                                        <div class="risk-list-item">
                                                            <p>{{ $obligation['text'] }}</p>
                                                            @if($obligation['period'])
                                                                <small>{{ $obligation['period'] }}</small>
                                                            @endif
                                                        </div>
                                                    @empty
                                                        <p class="risk-empty">Tidak ada kewajiban yang dicantumkan.</p>
                                                    @endforelse
                                                </details>

                                                <details>
                                                    <summary>Kewenangan <span>{{ count($profile->authorities ?? []) }}</span></summary>
                                                    @forelse($profile->authorities ?? [] as $authority)
                                                        <div class="risk-list-item">
                                                            <p>{{ $authority['parameter'] ?: 'Parameter tidak dicantumkan' }}</p>
                                                            <small>{{ $authority['authority'] ?: 'Kewenangan tidak dicantumkan' }}</small>
                                                        </div>
                                                    @empty
                                                        <p class="risk-empty">Tidak ada kewenangan yang dicantumkan.</p>
                                                    @endforelse
                                                </details>
                                            </div>
                                        </article>
                                    @endforeach
                                </div>
                            </div>
                        </details>
                    @empty
                        <div class="empty-state kbli-no-risk">
                            <h2>Profil risiko belum dicantumkan pada OSS</h2>
                            <p>Kode ini tetap merupakan KBLI 2025 yang sah, tetapi data risiko terperinci belum tersedia pada halaman publik OSS saat pembaruan terakhir.</p>
                        </div>
                    @endforelse
                </div>
            </main>

            <aside>
                <div class="kbli-summary-card">
                    <span class="detail-label">Ringkasan</span>
                    <div class="summary-row">
                        <span>Versi</span>
                        <strong>KBLI 2025</strong>
                    </div>
                    <div class="summary-row">
                        <span>Tingkat risiko</span>
                        <strong>{{ implode(', ', $kbli->risk_levels ?? []) ?: 'Belum dicantumkan' }}</strong>
                    </div>
                    <div class="summary-row">
                        <span>Perizinan</span>
                        <strong>{{ implode(', ', $kbli->licenses ?? []) ?: 'Belum dicantumkan' }}</strong>
                    </div>
                    <div class="summary-row">
                        <span>Pembaruan data</span>
                        <strong>{{ optional($kbli->source_updated_at)->timezone('Asia/Jakarta')->format('d M Y') ?: 'Tidak tercatat' }}</strong>
                    </div>
                    <a class="btn btn-outline-primary w-100" href="{{ $kbli->source_url }}" target="_blank" rel="noopener noreferrer">Bandingkan di OSS</a>
                    <a class="btn btn-primary w-100" href="{{ route('proposal.create') }}">Konsultasikan KBLI</a>
                </div>

                <div class="legal-notice">
                    <strong>Penting</strong>
                    <p>Informasi ini membantu pemeriksaan awal dan bukan keputusan perizinan. Hasil resmi tetap mengikuti data proyek dan proses pada sistem OSS.</p>
                </div>
            </aside>
        </div>
    </div>
</section>
@endsection

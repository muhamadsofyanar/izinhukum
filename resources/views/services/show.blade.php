@extends('layouts.app')

@section('title', e($content['seo_title']))
@section('meta_description', e($content['seo_description']))
@section('canonical', route('services.show', $service))

@php
    $minimumPrice = (int) $service->packages->where('price', '>', 0)->min('price');
    $defaultPackage = old('service_package_id', $service->packages->firstWhere('is_popular', true)?->id ?? $service->packages->first()?->id);
    $defaultMessage = 'Saya ingin konsultasi '.$service->name.' dan membutuhkan informasi persyaratan, estimasi proses, serta penawaran.'.($activeCoupon ? ' Saya ingin menggunakan promo '.$activeCoupon->code.'.' : '');
    $featuredPromo = collect($promoOffers)->sortBy('total')->first();
    $promoRemaining = $activeCoupon?->maximum_redemptions !== null
        ? max(0, (int) $activeCoupon->maximum_redemptions - (int) $activeCoupon->redemptions_count)
        : null;
@endphp

@push('head')
<script type="application/ld+json">@json($structuredData)</script>
@endpush

@section('content')
<section class="service-v21-hero">
    <div class="container">
        <nav class="breadcrumb-nav" aria-label="Breadcrumb"><a href="{{ route('home') }}">Beranda</a><span>/</span><a href="{{ route('services.index') }}">Layanan</a><span>/</span><span>{{ $service->short_name }}</span></nav>
        <div class="row align-items-center g-5">
            <div class="col-lg-7">
                <span class="service-v21-kicker">{{ $content['eyebrow'] }}</span>
                <h1>{{ $content['headline'] }}</h1>
                <p>{{ $content['subheadline'] }}</p>
                <div class="service-v21-actions"><a class="btn btn-primary btn-lg" href="#service-consultation">Konsultasi {{ $service->short_name }} →</a><a class="btn btn-ghost btn-lg" href="#service-packages">Lihat paket & harga</a></div>
                <div class="service-v21-trust"><span>✓ Konsultasi awal gratis</span><span>✓ Biaya dikonfirmasi di awal</span><span>✓ Lanjut deal via WhatsApp</span></div>
            </div>
            <div class="col-lg-5">
                <aside class="service-v21-summary">
                    <span>Layanan {{ $service->short_name }}</span>
                    <h2>{{ $service->name }}</h2>
                    <p>{{ $service->summary }}</p>
                    <div class="service-v21-summary-stats">
                        <div><small>Harga</small><strong>{{ $minimumPrice > 0 ? 'Mulai Rp'.number_format($minimumPrice, 0, ',', '.') : 'Sesuai kebutuhan' }}</strong></div>
                        <div><small>Pilihan</small><strong>{{ $service->packages->count() }} paket</strong></div>
                        <div><small>Persyaratan</small><strong>{{ count($service->requirements ?: []) ?: 'Diperiksa' }}</strong></div>
                    </div>
                    <a class="btn btn-warning w-100" href="#service-consultation">Cek kebutuhan saya</a>
                    <small>Belum ada pembayaran pada tahap konsultasi.</small>
                </aside>
            </div>
        </div>
    </div>
</section>

@if($activeCoupon && $featuredPromo)
<section class="service-v21-promo-strip">
    <div class="container">
        <div class="service-v21-promo-card">
            <div><span>Promo aktif hingga {{ $activeCoupon->ends_at?->translatedFormat('j F Y') }}</span><h2>Hemat {{ $activeCoupon->discountLabel() }} untuk {{ $service->name }}</h2><p>Kode <strong>{{ $activeCoupon->code }}</strong> sudah otomatis terisi pada formulir. Harga paket mulai <del>Rp{{ number_format($featuredPromo['subtotal'], 0, ',', '.') }}</del> <strong>Rp{{ number_format($featuredPromo['total'], 0, ',', '.') }}</strong>.</p></div>
            <div class="service-v21-promo-action">@if($promoRemaining !== null)<small>Sisa kuota</small><strong>{{ $promoRemaining }}</strong>@endif<a class="btn btn-warning" href="#service-consultation">Ambil promo →</a></div>
        </div>
    </div>
</section>
@endif

<section class="service-v21-benefits">
    <div class="container"><div class="service-v21-benefit-grid">@foreach($content['benefits'] as $index=>$benefit)<article><span>{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span><strong>{{ $benefit }}</strong></article>@endforeach</div></div>
</section>

<section class="section" id="service-packages">
    <div class="container">
        <div class="section-heading section-heading-split"><div><span class="eyebrow">Paket & ruang lingkup</span><h2>Pilih paket yang paling mendekati kebutuhan</h2><p>@if($service->packages->contains('is_estimated', true)) Harga berlabel perkiraan dikonfirmasi setelah kondisi dokumen dan ruang lingkup diperiksa. @else Kebutuhan di luar paket akan dikonfirmasi sebelum pekerjaan dimulai. @endif</p></div><a class="text-link" href="#service-consultation">Belum yakin? Konsultasi dahulu →</a></div>
        <div class="service-v21-package-grid">
            @forelse($service->packages as $package)
                @php($promoOffer = $promoOffers[$package->id] ?? null)
                <article class="service-v21-package {{ $package->is_popular ? 'is-popular' : '' }}">
                    @if($package->is_popular)<span class="service-v21-popular">Paling dipilih</span>@endif
                    <div class="service-v21-package-head"><small>{{ $service->short_name }}</small><h3>{{ $package->name }}</h3><p>{{ $package->tagline }}</p></div>
                    <div class="service-v21-price">@if($promoOffer)<del>{{ $package->formattedPrice() }}</del><strong>Rp{{ number_format($promoOffer['total'], 0, ',', '.') }}</strong><small>Dengan kupon {{ $activeCoupon->code }}</small>@else <strong>{{ $package->price > 0 ? $package->formattedPrice() : 'Harga melalui penawaran' }}</strong>@if($package->price_suffix && !in_array($package->price_suffix, ['mulai','penawaran'], true))<small>{{ $package->price_suffix }}</small>@endif @endif</div>
                    @if($package->is_estimated)<span class="badge badge-estimated mb-3">Harga Perkiraan</span>@endif
                    <ul>@foreach($package->features ?: [] as $feature)<li><span>✓</span>{{ $feature }}</li>@endforeach</ul>
                    <a class="btn btn-primary w-100" href="{{ route('proposal.create', ['paket'=>$package->id]) }}">Pilih paket</a>
                </article>
            @empty
                <div class="empty-state"><h2>Paket sedang disiapkan</h2><p>Hubungi admin melalui WhatsApp untuk mendapatkan penawaran.</p></div>
            @endforelse
        </div>
    </div>
</section>

<section class="service-v21-process-section">
    <div class="container">
        <div class="row g-5 align-items-start"><div class="col-lg-4"><span class="service-v21-kicker">Tahapan kerja</span><h2>Proses jelas sejak awal</h2><p>Setiap tahap disesuaikan dengan kondisi dokumen dan ruang lingkup layanan {{ $service->short_name }}.</p></div><div class="col-lg-8"><div class="service-v21-process">@foreach($content['process'] as $index=>$step)<article><span>{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span><div><h3>{{ $step['title'] }}</h3><p>{{ $step['description'] ?? '' }}</p></div></article>@endforeach</div></div></div>
    </div>
</section>

@if(!empty($service->requirements))
<section class="section"><div class="container"><div class="service-v21-requirements"><div><span class="service-v21-kicker">Persyaratan awal</span><h2>Siapkan data berikut</h2><p>Daftar ini merupakan persiapan awal. Tim akan memeriksa dan mengonfirmasi dokumen tambahan sesuai kondisi pemohon.</p><a class="btn btn-outline-primary" href="#service-consultation">Periksa kelengkapan saya</a></div><ul>@foreach($service->requirements as $requirement)<li><span>✓</span><strong>{{ $requirement }}</strong></li>@endforeach</ul></div></div></section>
@endif

<section class="section section-soft">
    <div class="container"><div class="row g-5"><div class="col-lg-5"><span class="eyebrow">Pertanyaan umum</span><h2>Hal penting sebelum memulai {{ $service->short_name }}</h2><p>Jawaban berikut membantu pemeriksaan awal. Kondisi setiap klien tetap dapat memerlukan penyesuaian.</p></div><div class="col-lg-7"><div class="service-v21-faq">@foreach($content['faqs'] as $index=>$faq)<details @if($index === 0) open @endif><summary>{{ $faq['question'] }}<span>+</span></summary><p>{{ $faq['answer'] }}</p></details>@endforeach</div></div></div></div>
</section>

<section class="service-v21-form-section" id="service-consultation">
    <div class="container">
        <div class="row g-4 align-items-start">
            <div class="col-lg-7">
                <a class="btn btn-primary" href="{{ route('proposal.create') }}">Pilih paket dan lanjutkan pesanan</a>
            </div>
            <div class="col-lg-5"><aside class="service-v21-assurance"><span>Yang terjadi berikutnya</span><ol><li><strong>Permintaan tercatat</strong><small>Nomor referensi dibuat otomatis.</small></li><li><strong>WhatsApp terbuka</strong><small>Anda mengirim konfirmasi secara manual.</small></li><li><strong>Admin memeriksa</strong><small>Kebutuhan, dokumen, biaya, dan estimasi dikonfirmasi.</small></li><li><strong>Penawaran resmi</strong><small>Ruang lingkup disetujui sebelum proses dimulai.</small></li></ol><div><small>Kontak resmi</small><strong>{{ config('company.phone') }}</strong><span>{{ config('company.email') }}</span></div></aside></div>
        </div>
    </div>
</section>

@if($relatedServices->isNotEmpty())
<section class="section"><div class="container"><div class="section-heading section-heading-split"><div><span class="eyebrow">Layanan terkait</span><h2>Pilihan lain dalam {{ $service->category }}</h2></div><a class="text-link" href="{{ route('services.index') }}">Semua layanan →</a></div><div class="row g-3">@foreach($relatedServices as $related)<div class="col-md-4"><a class="service-v21-related" href="{{ route('services.show', $related) }}"><small>{{ $related->short_name }}</small><h3>{{ $related->name }}</h3><p>{{ $related->summary }}</p><strong>Lihat layanan →</strong></a></div>@endforeach</div></div></section>
@endif

<a class="service-v21-mobile-cta" href="#service-consultation">Konsultasi {{ $service->short_name }} →</a>
@endsection


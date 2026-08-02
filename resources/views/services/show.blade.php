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
                    <div class="service-v21-price">@if($promoOffer)<del>{{ $package->formattedPrice() }}</del><strong>Rp{{ number_format($promoOffer['total'], 0, ',', '.') }}</strong><small>Dengan kupon {{ $activeCoupon->code }}</small>@else @if($package->original_price)<del>Rp{{ number_format($package->original_price, 0, ',', '.') }}</del>@endif<strong>{{ $package->price > 0 ? $package->formattedPrice() : 'Minta penawaran' }}</strong>@if($package->price_suffix && !in_array($package->price_suffix, ['mulai','penawaran'], true))<small>{{ $package->price_suffix }}</small>@endif @endif</div>
                    @if($package->is_estimated)<span class="badge badge-estimated mb-3">Harga Perkiraan</span>@endif
                    <ul>@foreach($package->features ?: [] as $feature)<li><span>✓</span>{{ $feature }}</li>@endforeach</ul>
                    <button class="btn {{ $package->is_popular ? 'btn-primary' : 'btn-outline-primary' }} w-100 service-package-select" type="button" data-package="{{ $package->id }}">Pilih & konsultasikan</button>
                </article>
            @empty
                <div class="empty-state"><h2>Paket sedang disiapkan</h2><p>Isi formulir konsultasi untuk mendapatkan ruang lingkup dan penawaran.</p></div>
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
                <form class="service-v21-form" action="{{ route('proposal.store') }}" method="post">
                    @csrf
                    <input type="hidden" name="journey_source" value="service_landing">
                    <div class="service-v21-form-head"><span>Konsultasi {{ $service->short_name }}</span><h2>Ceritakan kebutuhan Anda</h2><p>Setelah tersimpan, Anda memperoleh nomor referensi dan melanjutkan pembahasan melalui WhatsApp.</p></div>
                    @if($errors->any())<div class="alert alert-danger">Periksa kembali data yang ditandai.</div>@endif
                    <label class="field"><span>Paket yang diminati</span><select class="form-select @error('service_package_id') is-invalid @enderror" id="service-v21-package" name="service_package_id"><option value="">Konsultasi dahulu</option>@foreach($service->packages as $package)<option value="{{ $package->id }}" @selected($defaultPackage == $package->id)>{{ $package->name }} · {{ $package->price > 0 ? $package->formattedPrice() : 'Penawaran' }}</option>@endforeach</select>@error('service_package_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</label>
                    @if($service->slug === 'pendirian-yayasan')
                    <div class="service-v21-qualification mb-3"><strong>3 pertanyaan cepat agar konsultasi lebih tepat</strong><small>Opsional, dapat dilewati bila belum tahu.</small></div>
                    <div class="row g-3">
                        <label class="field col-md-4"><span>Fokus kegiatan</span><select class="form-select" name="foundation_purpose"><option value="">Belum ditentukan</option><option value="social_education" @selected(old('foundation_purpose') === 'social_education')>Sosial/pendidikan</option><option value="religious" @selected(old('foundation_purpose') === 'religious')>Keagamaan</option><option value="humanitarian" @selected(old('foundation_purpose') === 'humanitarian')>Kemanusiaan</option><option value="mixed" @selected(old('foundation_purpose') === 'mixed')>Gabungan</option><option value="other" @selected(old('foundation_purpose') === 'other')>Lainnya</option></select></label>
                        <label class="field col-md-4"><span>Kesiapan saat ini</span><select class="form-select" name="foundation_readiness"><option value="">Belum ditentukan</option><option value="ready" @selected(old('foundation_readiness') === 'ready')>Nama & struktur siap</option><option value="partial" @selected(old('foundation_readiness') === 'partial')>Sebagian sudah siap</option><option value="starting" @selected(old('foundation_readiness') === 'starting')>Mulai dari awal</option></select></label>
                        <label class="field col-md-4"><span>Target mulai</span><select class="form-select" name="foundation_timeline"><option value="">Belum ditentukan</option><option value="immediately" @selected(old('foundation_timeline') === 'immediately')>Secepatnya</option><option value="thirty_days" @selected(old('foundation_timeline') === 'thirty_days')>Dalam 30 hari</option><option value="three_months" @selected(old('foundation_timeline') === 'three_months')>Dalam 1–3 bulan</option><option value="research" @selected(old('foundation_timeline') === 'research')>Masih riset</option></select></label>
                    </div>
                    @endif
                    <div class="row g-3">
                        <label class="field col-md-6"><span>Nama lengkap *</span><input class="form-control @error('name') is-invalid @enderror" name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="name">@error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror</label>
                        <label class="field col-md-6"><span>Nomor WhatsApp *</span><input class="form-control @error('phone') is-invalid @enderror" name="phone" value="{{ old('phone') }}" required maxlength="32" inputmode="tel" autocomplete="tel">@error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror</label>
                        <label class="field col-md-6"><span>Email</span><input class="form-control @error('email') is-invalid @enderror" type="email" name="email" value="{{ old('email') }}" maxlength="160" autocomplete="email">@error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror</label>
                        <label class="field col-md-6"><span>Nama usaha/organisasi</span><input class="form-control @error('company_name') is-invalid @enderror" name="company_name" value="{{ old('company_name') }}" maxlength="160">@error('company_name')<div class="invalid-feedback">{{ $message }}</div>@enderror</label>
                        <label class="field col-md-6"><span>Kota/Kabupaten</span><input class="form-control @error('city') is-invalid @enderror" name="city" value="{{ old('city') }}" maxlength="120">@error('city')<div class="invalid-feedback">{{ $message }}</div>@enderror</label>
                        <label class="field col-md-6"><span>Kode kupon</span><input class="form-control text-uppercase @error('coupon_code') is-invalid @enderror" name="coupon_code" value="{{ old('coupon_code', $activeCoupon?->code) }}" maxlength="32" placeholder="Jika ada" @readonly($activeCoupon)>@if($activeCoupon)<small>Promo otomatis aktif selama periode dan kuota tersedia.</small>@endif @error('coupon_code')<div class="invalid-feedback">{{ $message }}</div>@enderror</label>
                        <label class="field col-12"><span>Kebutuhan Anda</span><textarea class="form-control @error('message') is-invalid @enderror" name="message" rows="4" maxlength="3000">{{ old('message', $defaultMessage) }}</textarea>@error('message')<div class="invalid-feedback">{{ $message }}</div>@enderror</label>
                    </div>
                    <label class="consent-line mt-3"><input type="checkbox" name="privacy_consent" value="1" required @checked(old('privacy_consent'))> Saya menyetujui pemrosesan data untuk tindak lanjut sesuai <a href="{{ route('legal.privacy') }}" target="_blank">Kebijakan Privasi</a>.</label>
                    @error('privacy_consent')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    <button class="btn btn-warning btn-lg w-100 mt-4" type="submit">Kirim & lanjut ke WhatsApp →</button>
                    <small class="service-v21-safe">Pesan WhatsApp belum terkirim sampai Anda menekan kirim pada aplikasi WhatsApp.</small>
                </form>
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

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const select = document.getElementById('service-v21-package');
    document.querySelectorAll('.service-package-select').forEach(button => {
        button.addEventListener('click', () => {
            select.value = button.dataset.package;
            document.getElementById('service-consultation').scrollIntoView({behavior: 'smooth'});
        });
    });
});
</script>
@endpush

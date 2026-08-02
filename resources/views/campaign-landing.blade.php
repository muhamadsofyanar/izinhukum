@extends('layouts.app')

@section('title', e($headline))
@section('meta_description', e($subheadline))
@section('robots', 'noindex,follow')
@section('focused_campaign', '1')

@php
    $defaultPackage = old('service_package_id', $packages->firstWhere('is_popular', true)?->id ?? $packages->first()?->id);
    $serviceName = $campaign->service?->name ?: 'Legalitas bisnis';
@endphp

@section('content')
<section class="campaign-hero">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-7">
                <span class="campaign-kicker">{{ $serviceName }} · Konsultasi awal gratis</span>
                <h1>{{ $headline }}</h1>
                <p>{{ $subheadline }}</p>
                <div class="campaign-trust-row">
                    <span>✓ Biaya dijelaskan di awal</span>
                    <span>✓ Progres dapat dilacak</span>
                    <span>✓ Dilanjutkan via WhatsApp</span>
                </div>
                <a class="btn btn-warning btn-lg campaign-primary-cta" href="#campaign-form">{{ $campaign->cta_text }} →</a>
                <small class="campaign-microcopy">Isi ±1 menit. Belum ada pembayaran pada tahap ini.</small>
            </div>
            <div class="col-lg-5">
                <div class="campaign-proof-card">
                    <span>Alur sederhana</span>
                    <ol>
                        <li><strong>Isi kebutuhan singkat</strong><small>Agar konsultasi tidak dimulai dari nol.</small></li>
                        <li><strong>Dapatkan nomor referensi</strong><small>Permintaan langsung tercatat pada tim.</small></li>
                        <li><strong>Lanjutkan di WhatsApp</strong><small>Deal tetap dilakukan secara manual dengan admin.</small></li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
</section>

@if($packages->isNotEmpty())
<section class="campaign-packages">
    <div class="container">
        <div class="campaign-section-heading">
            <span>Pilihan layanan</span>
            <h2>Pilih yang paling mendekati kebutuhan Anda</h2>
            <p>Ruang lingkup dan biaya final tetap dikonfirmasi sebelum pekerjaan dimulai.</p>
        </div>
        <div class="campaign-package-grid">
            @foreach($packages->take(3) as $package)
                <article class="campaign-package-card {{ $package->is_popular ? 'is-popular' : '' }}">
                    @if($package->is_popular)<span class="campaign-popular">Paling dipilih</span>@endif
                    <small>{{ $package->service?->name }}</small>
                    <h3>{{ $package->name }}</h3>
                    <strong>@if($package->price === 0 && $package->is_estimated)Sesuai kebutuhan @else{{ $package->is_estimated ? 'Perkiraan ' : '' }}{{ $package->formattedPrice() }}@endif</strong>
                    @if($package->tagline)<p>{{ $package->tagline }}</p>@endif
                    <button class="btn btn-outline-primary campaign-pick-package" type="button" data-package="{{ $package->id }}">Pilih paket</button>
                </article>
            @endforeach
        </div>
    </div>
</section>
@endif

<section class="campaign-form-section" id="campaign-form">
    <div class="container">
        <div class="row g-4 align-items-start">
            <div class="col-lg-7">
                <form class="campaign-lead-form" action="{{ route('proposal.store') }}" method="post">
                    @csrf
                    <input type="hidden" name="journey_source" value="website">
                    <div class="campaign-form-heading">
                        <span>Langkah berikutnya</span>
                        <h2>Dapatkan arahan dan penawaran</h2>
                        <p>Lengkapi data berikut, lalu Anda akan diarahkan ke WhatsApp dengan nomor referensi.</p>
                    </div>
                    <label class="field"><span>Paket layanan</span><select class="form-select @error('service_package_id') is-invalid @enderror" id="campaign-package-select" name="service_package_id"><option value="">Konsultasi dahulu</option>@foreach($packages as $package)<option value="{{ $package->id }}" @selected($defaultPackage == $package->id)>{{ $package->service?->name }} · {{ $package->name }}</option>@endforeach</select>@error('service_package_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</label>
                    <div class="row g-3">
                        <label class="field col-md-6"><span>Nama lengkap *</span><input class="form-control @error('name') is-invalid @enderror" name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="name">@error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror</label>
                        <label class="field col-md-6"><span>Nomor WhatsApp *</span><input class="form-control @error('phone') is-invalid @enderror" name="phone" value="{{ old('phone') }}" required maxlength="32" inputmode="tel" autocomplete="tel">@error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror</label>
                        <label class="field col-md-6"><span>Email</span><input class="form-control @error('email') is-invalid @enderror" type="email" name="email" value="{{ old('email') }}" maxlength="160" autocomplete="email">@error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror</label>
                        <label class="field col-md-6"><span>Nama usaha/perusahaan</span><input class="form-control @error('company_name') is-invalid @enderror" name="company_name" value="{{ old('company_name') }}" maxlength="160">@error('company_name')<div class="invalid-feedback">{{ $message }}</div>@enderror</label>
                        <label class="field col-md-6"><span>Kota/Kabupaten</span><input class="form-control @error('city') is-invalid @enderror" name="city" value="{{ old('city') }}" maxlength="120">@error('city')<div class="invalid-feedback">{{ $message }}</div>@enderror</label>
                        <label class="field col-md-6"><span>Kode kupon</span><input class="form-control text-uppercase @error('coupon_code') is-invalid @enderror" name="coupon_code" value="{{ old('coupon_code') }}" maxlength="32" placeholder="Jika ada">@error('coupon_code')<div class="invalid-feedback">{{ $message }}</div>@enderror</label>
                        <label class="field col-12"><span>Ceritakan kebutuhan Anda</span><textarea class="form-control @error('message') is-invalid @enderror" name="message" rows="4" maxlength="3000" placeholder="Contoh: ingin mendirikan PT untuk usaha perdagangan dan membutuhkan NIB.">{{ old('message') }}</textarea>@error('message')<div class="invalid-feedback">{{ $message }}</div>@enderror</label>
                    </div>
                    <label class="consent-line mt-3"><input type="checkbox" name="privacy_consent" value="1" required @checked(old('privacy_consent'))> Saya menyetujui pemrosesan data untuk tindak lanjut sesuai <a href="{{ route('legal.privacy') }}" target="_blank">Kebijakan Privasi</a>.</label>
                    @error('privacy_consent')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    <button class="btn btn-warning btn-lg w-100 mt-4" type="submit">Kirim & lanjut ke WhatsApp →</button>
                    <p class="campaign-form-safe">Data hanya digunakan untuk konsultasi dan pelaksanaan layanan Anda.</p>
                </form>
            </div>
            <div class="col-lg-5">
                <aside class="campaign-assurance">
                    <span>Mengapa isi form dahulu?</span>
                    <h2>Respons lebih cepat dan tepat</h2>
                    <p>Admin menerima layanan, identitas, dan konteks kebutuhan sebelum percakapan WhatsApp dimulai.</p>
                    <ul>
                        <li>Tidak perlu mengulang kebutuhan dari awal.</li>
                        <li>Nomor referensi dibuat otomatis.</li>
                        <li>Penawaran resmi dapat disetujui secara digital.</li>
                        <li>Deal dan percakapan tetap dikendalikan admin.</li>
                    </ul>
                    <div class="campaign-company-box"><small>Diselenggarakan oleh</small><strong>{{ config('company.name') }}</strong><span>{{ config('company.email') }} · {{ config('company.phone') }}</span></div>
                </aside>
            </div>
        </div>
    </div>
</section>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const select = document.getElementById('campaign-package-select');
    document.querySelectorAll('.campaign-pick-package').forEach(button => {
        button.addEventListener('click', () => {
            select.value = button.dataset.package;
            document.getElementById('campaign-form').scrollIntoView({behavior: 'smooth'});
        });
    });
});
</script>
@endpush

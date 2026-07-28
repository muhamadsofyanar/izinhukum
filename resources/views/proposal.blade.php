@extends('layouts.app')

@section('title', 'Minta Proposal dan Penawaran')
@section('meta_description', 'Ceritakan kebutuhan legalitas Anda dan dapatkan arahan serta penawaran dari tim IzinHukum.')

@section('content')
<section class="page-hero page-hero-compact">
    <div class="container">
        <span class="eyebrow">Mulai konsultasi</span>
        <h1>Ceritakan kebutuhan legalitas Anda</h1>
        <p>Isi data singkat berikut. Tim IzinHukum akan menghubungi Anda untuk mengonfirmasi kebutuhan, dokumen, dan biaya.</p>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="row g-5">
            <div class="col-lg-7">
                <form class="form-card" action="{{ route('proposal.store') }}" method="post">
                    @csrf
                    <div class="form-section">
                        <span class="form-number">01</span>
                        <div>
                            <h2>Pilih layanan</h2>
                            <p>Anda boleh mengosongkan pilihan bila masih ingin berkonsultasi.</p>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label" for="service_package_id">Paket layanan</label>
                        <select class="form-select @error('service_package_id') is-invalid @enderror" id="service_package_id" name="service_package_id">
                            <option value="">Belum tahu / konsultasi dahulu</option>
                            @foreach($packages->groupBy(fn($package) => $package->service->name) as $serviceName => $servicePackages)
                                <optgroup label="{{ $serviceName }}">
                                    @foreach($servicePackages as $package)
                                        <option value="{{ $package->id }}" @selected(old('service_package_id', $selectedPackage) == $package->id)>
                                            {{ $package->name }} — {{ $package->is_estimated ? 'perkiraan ' : '' }}{{ $package->formattedPrice() }}
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        @error('service_package_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="form-section mt-5">
                        <span class="form-number">02</span>
                        <div>
                            <h2>Data yang dapat kami hubungi</h2>
                            <p>Data hanya digunakan untuk menindaklanjuti permintaan Anda.</p>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="name">Nama lengkap <span>*</span></label>
                            <input class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="name">
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="phone">Nomor WhatsApp <span>*</span></label>
                            <input class="form-control @error('phone') is-invalid @enderror" id="phone" name="phone" value="{{ old('phone') }}" required maxlength="32" inputmode="tel" autocomplete="tel">
                            @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="email">Email</label>
                            <input class="form-control @error('email') is-invalid @enderror" id="email" name="email" value="{{ old('email') }}" type="email" maxlength="160" autocomplete="email">
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="city">Kota/Kabupaten</label>
                            <input class="form-control @error('city') is-invalid @enderror" id="city" name="city" value="{{ old('city') }}" maxlength="120">
                            @error('city')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="company_name">Nama perusahaan/organisasi <small>(jika ada)</small></label>
                            <input class="form-control @error('company_name') is-invalid @enderror" id="company_name" name="company_name" value="{{ old('company_name') }}" maxlength="160">
                            @error('company_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="message">Ceritakan kebutuhan Anda</label>
                            <textarea class="form-control @error('message') is-invalid @enderror" id="message" name="message" rows="5" maxlength="3000" placeholder="Contoh: ingin mendirikan PT untuk usaha perdagangan di Sumedang dan membutuhkan NIB.">{{ old('message') }}</textarea>
                            @error('message')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <button class="btn btn-primary btn-lg mt-4" type="submit">Kirim permintaan</button>
                    <p class="form-privacy">Dengan mengirim formulir, Anda menyetujui tim IzinHukum menghubungi Anda terkait permintaan ini.</p>
                </form>
            </div>
            <div class="col-lg-5">
                <aside class="side-panel sticky-lg-top">
                    <span class="eyebrow">Yang terjadi berikutnya</span>
                    <ol class="side-steps">
                        <li><span>1</span><div><strong>Permintaan tercatat</strong><small>Anda memperoleh nomor referensi.</small></div></li>
                        <li><span>2</span><div><strong>Tim menghubungi Anda</strong><small>Kebutuhan dan kelengkapan dokumen dikonfirmasi.</small></div></li>
                        <li><span>3</span><div><strong>Penawaran final</strong><small>Anda menerima ruang lingkup dan biaya sebelum proses.</small></div></li>
                    </ol>
                    <hr>
                    <p>Perlu respons lebih cepat?</p>
                    <a class="text-link" target="_blank" rel="noopener" href="https://wa.me/{{ config('company.whatsapp') }}?text={{ urlencode('Halo IzinHukum, saya ingin konsultasi legalitas.') }}">Chat WhatsApp {{ config('company.phone') }} →</a>
                </aside>
            </div>
        </div>
    </div>
</section>
@endsection

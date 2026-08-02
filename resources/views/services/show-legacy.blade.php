@extends('layouts.app')

@section('title', $service->name)
@section('meta_description', $service->summary)
@section('content')
<section class="service-hero">
    <div class="container">
        <nav class="breadcrumb-nav" aria-label="Breadcrumb"><a href="{{ route('home') }}">Beranda</a><span>/</span><a href="{{ route('services.index') }}">Layanan</a><span>/</span><span>{{ $service->short_name }}</span></nav>
        <div class="row align-items-end g-4"><div class="col-lg-8"><span class="eyebrow">{{ $service->category }}</span><h1>{{ $service->name }}</h1><p>{{ $service->description }}</p></div><div class="col-lg-4 text-lg-end"><a class="btn btn-primary" href="{{ route('proposal.create', array_filter(['ref' => request('ref')])) }}">Konsultasi layanan ini</a></div></div>
    </div>
</section>
<section class="section"><div class="container"><div class="section-heading"><span class="eyebrow">Pilihan paket</span><h2>Sesuaikan dengan kebutuhan Anda</h2>@if($service->packages->contains('is_estimated', true))<p><span class="badge badge-estimated">Harga Perkiraan</span> Nominal akan dikonfirmasi setelah dokumen dan ruang lingkup diperiksa.</p>@else<p>Harga paket belum termasuk biaya di luar ruang lingkup. Tim mengonfirmasi kebutuhan sebelum proses dimulai.</p>@endif</div><div class="row g-4 justify-content-center">@foreach($service->packages as $package)<div class="col-md-6 col-xl-4"><x-price-card :package="$package" /></div>@endforeach</div></div></section>
@if(!empty($service->requirements))<section class="section pt-0"><div class="container"><div class="requirements-panel"><div><span class="eyebrow">Persyaratan awal</span><h2>Siapkan data berikut</h2><p>Tim akan mengonfirmasi kelengkapan dan dokumen tambahan sesuai kondisi pemohon.</p></div><ul>@foreach($service->requirements as $requirement)<li><span>✓</span>{{ $requirement }}</li>@endforeach</ul></div></div></section>@endif
<section class="section section-soft"><div class="container"><div class="row g-5 align-items-center"><div class="col-lg-5"><span class="eyebrow">Cara mulai</span><h2>Dokumen tepat, proses lebih lancar</h2><p>Setelah memilih paket, tim memeriksa kebutuhan dan mengirim daftar dokumen yang relevan.</p></div><div class="col-lg-7"><div class="step-grid"><div><span>1</span><strong>Pilih paket</strong><small>Ajukan melalui formulir.</small></div><div><span>2</span><strong>Konsultasi</strong><small>Konfirmasi ruang lingkup.</small></div><div><span>3</span><strong>Lengkapi data</strong><small>Ikuti daftar dokumen.</small></div><div><span>4</span><strong>Pantau proses</strong><small>Terima pembaruan dari tim.</small></div></div></div></div></div></section>
@endsection

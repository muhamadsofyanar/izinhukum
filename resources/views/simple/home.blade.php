@extends('layouts.app')
@section('title','Layanan Legalitas Usaha')
@section('content')
<section class="section-padding"><div class="container"><div class="row align-items-center g-5">
<div class="col-lg-7"><span class="eyebrow">IzinHukum</span><h1>Legalitas usaha, dengan proses yang jelas.</h1><p class="lead">Pilih layanan, pelajari persyaratan dan paketnya, lalu lanjutkan pesanan melalui WhatsApp. Harga dan ruang lingkup disepakati sebelum pekerjaan dimulai.</p><a class="btn btn-primary" href="{{ route('services.index') }}">Lihat layanan & paket</a></div>
<div class="col-lg-5"><div class="p-4 border rounded-4"><h2 class="h4">Cara memesan</h2><ol><li>Pilih layanan dan paket.</li><li>Isi nama serta nomor WhatsApp.</li><li>Konfirmasi kebutuhan dan biaya dengan admin.</li><li>Terima invoice, bayar, lalu konfirmasi melalui WhatsApp.</li></ol></div></div></div></div></section>
<section class="section-padding"><div class="container"><h2>Layanan kami</h2><div class="row g-4 mt-2">@foreach($services as $service)<div class="col-md-6 col-lg-4"><article class="h-100 border rounded-4 p-4"><small>{{ $service->category }}</small><h3 class="h4 mt-2">{{ $service->name }}</h3><p>{{ $service->summary }}</p><a class="btn btn-outline-primary" href="{{ route('services.show',$service) }}">Detail, persyaratan & harga</a></article></div>@endforeach</div></div></section>
@endsection

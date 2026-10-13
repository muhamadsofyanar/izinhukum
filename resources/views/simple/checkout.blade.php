@extends('layouts.app')
@section('title','Pesan Layanan')
@section('robots','noindex,follow')
@section('content')
<section class="section-padding"><div class="container" style="max-width:760px"><h1>Pesan layanan</h1><p>Pemesanan ini belum merupakan tagihan. Admin akan mengonfirmasi kebutuhan, harga akhir, dan persyaratan melalui WhatsApp.</p>
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<form method="post" action="{{ route('proposal.store') }}" class="row g-3">@csrf
<div class="col-12"><label class="form-label" for="package">Paket layanan *</label><select class="form-select" name="service_package_id" id="package" required><option value="">Pilih paket</option>@foreach($packages as $package)<option value="{{ $package->id }}" @selected(old('service_package_id',$selectedPackage)==$package->id)>{{ $package->service->name }} · {{ $package->name }} · {{ $package->price > 0 ? 'Rp'.number_format($package->price,0,',','.') : 'Harga melalui penawaran' }}</option>@endforeach</select></div>
<div class="col-md-6"><label class="form-label" for="name">Nama *</label><input id="name" class="form-control" name="name" value="{{ old('name') }}" maxlength="120" autocomplete="name" required></div>
<div class="col-md-6"><label class="form-label" for="phone">Nomor WhatsApp *</label><input id="phone" class="form-control" name="phone" type="tel" value="{{ old('phone') }}" maxlength="32" autocomplete="tel" required></div>
<div class="col-md-6"><label class="form-label" for="company">Nama usaha</label><input id="company" class="form-control" name="company_name" value="{{ old('company_name') }}" maxlength="160"></div>
<div class="col-md-6"><label class="form-label" for="city">Kota</label><input id="city" class="form-control" name="city" value="{{ old('city') }}" maxlength="120"></div>
<div class="col-12"><label class="form-label" for="message">Kebutuhan atau catatan</label><textarea id="message" class="form-control" name="message" rows="3" maxlength="3000">{{ old('message') }}</textarea></div>
<div style="display:none" aria-hidden="true"><input name="website" tabindex="-1" autocomplete="off"></div><input type="hidden" name="form_started_at" value="{{ now()->timestamp }}">
<div class="col-12"><label><input type="checkbox" name="privacy_consent" value="1" required @checked(old('privacy_consent'))> Saya menyetujui <a href="{{ route('legal.privacy') }}">kebijakan privasi</a> dan dihubungi untuk pesanan ini.</label></div>
<div class="col-12"><button class="btn btn-primary" type="submit">Catat pesanan & lanjutkan</button></div></form></div></section>
@endsection

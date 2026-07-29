@extends('layouts.admin')
@section('title', 'Perangkat WhatsApp')
@section('heading', 'Perangkat StarSender')
@section('content')
@include('admin.whatsapp._nav')
<div class="wa-grid">
<section class="wa-card wa-span-4">
<h2>Sinkronisasi provider</h2>
<p class="wa-muted">Daftar lokal menyimpan metadata dan pembagian peran. API key perangkat tetap berada di Coolify.</p>
<form method="post" action="{{ route('admin.whatsapp.devices.sync') }}">@csrf<button class="btn btn-primary" @disabled(!$providerReady)>Sinkronkan perangkat</button></form>
<hr>
<h3>Buat perangkat dan scan</h3>
<form method="post" action="{{ route('admin.whatsapp.devices.create') }}" class="wa-form-grid">@csrf
<div class="full"><label>Nama perangkat</label><input class="form-control" name="name" placeholder="IzinHukum Support" required></div>
<div class="full"><button class="btn btn-outline-primary" @disabled(!$providerReady)>Minta QR/scan</button></div>
</form>
@if(!$providerReady)<div class="alert alert-warning mt-3">Account API Key atau integrasi belum aktif.</div>@endif
</section>
<section class="wa-card wa-span-8">
<h2>Daftar perangkat lokal</h2>
@forelse($devices as $device)
<details class="wa-card mt-3" @if($loop->first) open @endif>
<summary><strong>{{ $device->name }}</strong> <span class="wa-status {{ $device->status }}">{{ $device->status }}</span> <small>{{ $device->phone ?: 'Nomor belum terbaca' }} · provider #{{ $device->provider_id ?: '-' }}</small></summary>
<form method="post" action="{{ route('admin.whatsapp.devices.update',$device) }}" class="wa-form-grid mt-3">@csrf @method('put')
<div><label>Peran</label><select class="form-select" name="role">@foreach(['default','transaction','support','partner','campaign'] as $role)<option value="{{ $role }}" @selected($device->role===$role)>{{ $role }}</option>@endforeach</select></div>
<div><label>Batas lokal per hari</label><input class="form-control" type="number" name="daily_limit" min="1" max="500" value="{{ $device->daily_limit }}"></div>
<div><label><input type="checkbox" name="is_enabled" value="1" @checked($device->is_enabled)> Aktif lokal</label></div><div><label><input type="checkbox" name="is_default" value="1" @checked($device->is_default)> Perangkat default</label></div>
<div class="full"><button class="btn btn-outline-primary">Simpan pengaturan lokal</button></div>
</form>
<div class="wa-inline-actions mt-3">
<form method="post" action="{{ route('admin.whatsapp.devices.relog',$device) }}">@csrf<button class="btn btn-sm btn-outline-primary">Relog provider</button></form>
</div>
<details class="wa-danger-zone wa-card mt-3"><summary>Zona berbahaya</summary><form method="post" action="{{ route('admin.whatsapp.devices.delete',$device) }}" class="wa-form-grid mt-3">@csrf @method('delete')<div class="full"><label>Ketik HAPUS PERANGKAT</label><input class="form-control" name="confirmation" required></div><div class="full"><button class="btn btn-danger" onclick="return confirm('Tindakan ini menghapus perangkat pada provider. Lanjutkan?')">Hapus perangkat provider</button></div></form></details>
</details>
@empty<p class="wa-muted">Belum ada perangkat lokal. Tekan Sinkronkan perangkat setelah Account API Key aktif.</p>@endforelse
</section>
</div>
@endsection

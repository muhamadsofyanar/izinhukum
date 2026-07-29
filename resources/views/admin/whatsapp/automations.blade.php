@extends('layouts.admin')
@section('title', 'Otomasi WhatsApp')
@section('heading', 'Otomasi WhatsApp')
@section('content')
@include('admin.whatsapp._nav')
<div class="alert alert-warning"><strong>Aman secara default:</strong> aktifkan otomasi hanya setelah pesan uji berhasil. Feature flag notifikasi transaksi atau autoreply tetap harus aktif.</div>
<div class="wa-grid">
<section class="wa-card wa-span-4">
<h2>Autoreply kata kunci baru</h2>
<form method="post" action="{{ route('admin.whatsapp.automations.keywords.store') }}" class="wa-form-grid">@csrf
<div class="full"><label>Key unik</label><input class="form-control" name="key" placeholder="keyword_harga" required></div>
<div class="full"><label>Nama</label><input class="form-control" name="name" required></div>
<div class="full"><label>Template balasan</label><select class="form-select" name="template_id" required>@foreach($templates as $template)<option value="{{ $template->id }}">{{ $template->category }} · {{ $template->name }}</option>@endforeach</select></div>
<div class="full"><label>Kata kunci</label><textarea class="form-control" name="keywords" rows="4" placeholder="HARGA, BIAYA, TARIF" required></textarea></div>
<div class="full"><button class="btn btn-primary">Buat dalam keadaan nonaktif</button></div>
</form>
</section>
<section class="wa-card wa-span-8">
<h2>Daftar otomasi</h2>
@foreach($automations as $automation)
<details class="wa-card mt-3" @if($loop->first) open @endif>
<summary><strong>{{ $automation->name }}</strong> <span class="wa-status {{ $automation->is_enabled ? 'ok' : 'warn' }}">{{ $automation->is_enabled ? 'Aktif' : 'Nonaktif' }}</span> <small>{{ $automation->trigger }}</small></summary>
<form method="post" action="{{ route('admin.whatsapp.automations.update',$automation) }}" class="wa-form-grid mt-3">@csrf @method('put')
<div class="full"><label>Nama</label><input class="form-control" name="name" value="{{ $automation->name }}" required></div>
<div class="full"><label>Template</label><select class="form-select" name="template_id">@foreach($templates as $template)<option value="{{ $template->id }}" @selected($automation->template_id===$template->id)>{{ $template->category }} · {{ $template->name }}</option>@endforeach</select></div>
<div><label>Jeda, menit</label><input class="form-control" type="number" min="0" max="43200" name="delay_minutes" value="{{ $automation->delay_minutes }}" required></div>
<div><label><input type="checkbox" name="is_enabled" value="1" @checked($automation->is_enabled)> Aktifkan</label></div>
@if($automation->trigger==='keyword')<div class="full"><label>Kata kunci</label><textarea class="form-control" name="keywords" rows="3">{{ implode(', ', data_get($automation->conditions,'keywords',[])) }}</textarea></div>@endif
<div class="full"><button class="btn btn-outline-primary">Simpan</button></div>
</form>
</details>
@endforeach
</section>
</div>
@endsection

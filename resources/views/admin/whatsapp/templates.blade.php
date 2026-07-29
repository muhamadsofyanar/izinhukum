@extends('layouts.admin')
@section('title', 'Template WhatsApp')
@section('heading', 'Template WhatsApp')
@section('content')
@include('admin.whatsapp._nav')
<div class="wa-grid">
<section class="wa-card wa-span-4">
<h2>Template baru</h2>
<form method="post" action="{{ route('admin.whatsapp.templates.store') }}" class="wa-form-grid">@csrf
<div class="full"><label>Key unik</label><input class="form-control" name="key" placeholder="followup_konsultasi" required></div>
<div class="full"><label>Nama</label><input class="form-control" name="name" required></div>
<div><label>Kategori</label><input class="form-control" name="category" value="support" required></div>
<div><label>Tipe</label><select class="form-select" name="message_type"><option value="text">Teks</option><option value="image">Gambar</option><option value="document">Dokumen</option><option value="video">Video</option><option value="audio">Audio</option><option value="media">Media</option></select></div>
<div class="full"><label>Deskripsi</label><textarea class="form-control" name="description" rows="2"></textarea></div>
<div class="full"><label>Isi</label><textarea class="form-control" name="body" rows="8" required>Halo @{{nama_pelanggan}},</textarea><small class="wa-muted">Variabel ditulis dengan format @{{nama_variabel}}.</small></div>
<div class="full"><label>URL media</label><input class="form-control" type="url" name="media_url"></div>
<div><label><input type="checkbox" name="is_enabled" value="1" checked> Aktif</label></div><div><label><input type="checkbox" name="is_marketing" value="1"> Promosi</label></div>
<div class="full"><button class="btn btn-primary">Simpan template</button></div>
</form>
</section>
<section class="wa-card wa-span-8">
<div class="wa-inline-actions justify-content-between"><h2 class="mb-0">Daftar template</h2><form method="get"><select class="form-select" name="category" onchange="this.form.submit()"><option value="">Semua kategori</option>@foreach($categories as $item)<option value="{{ $item }}" @selected($category===$item)>{{ $item }}</option>@endforeach</select></form></div>
@foreach($templates as $template)
<details class="wa-card mt-3" @if($loop->first) open @endif>
<summary><strong>{{ $template->name }}</strong> <span class="wa-status {{ $template->is_enabled ? 'ok' : 'warn' }}">{{ $template->is_enabled ? 'Aktif' : 'Nonaktif' }}</span> <small>{{ $template->key }} · v{{ $template->version }}</small></summary>
<form method="post" action="{{ route('admin.whatsapp.templates.update',$template) }}" class="wa-form-grid mt-3">@csrf @method('put')
<div><label>Key</label><input class="form-control" name="key" value="{{ $template->key }}" required></div><div><label>Nama</label><input class="form-control" name="name" value="{{ $template->name }}" required></div>
<div><label>Kategori</label><input class="form-control" name="category" value="{{ $template->category }}" required></div><div><label>Tipe</label><select class="form-select" name="message_type">@foreach(['text','image','document','video','audio','media'] as $item)<option value="{{ $item }}" @selected($template->message_type===$item)>{{ $item }}</option>@endforeach</select></div>
<div class="full"><label>Deskripsi</label><textarea class="form-control" name="description" rows="2">{{ $template->description }}</textarea></div>
<div class="full"><label>Isi</label><textarea class="form-control" name="body" rows="8" required>{{ $template->body }}</textarea><small class="wa-muted">Variabel: {{ implode(', ', $template->variables ?? []) ?: 'Tidak ada' }}</small></div>
<div class="full"><label>URL media</label><input class="form-control" type="url" name="media_url" value="{{ $template->media_url }}"></div>
<div><label><input type="checkbox" name="is_enabled" value="1" @checked($template->is_enabled)> Aktif</label></div><div><label><input type="checkbox" name="is_marketing" value="1" @checked($template->is_marketing)> Promosi</label></div>
<div class="full"><button class="btn btn-primary">Simpan versi baru</button></div>
</form>
</details>
@endforeach
</section>
</div>
@endsection

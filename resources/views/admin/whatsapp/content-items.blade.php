@extends('layouts.admin')
@section('title', 'Bank Konten')
@section('heading', 'Bank Konten')
@section('content')
@include('admin.whatsapp._nav')
<div class="wa-grid">
<section class="wa-card wa-span-4">
<h2>{{ $editing ? 'Ubah konten' : 'Tambah konten' }}</h2>
<form method="post" enctype="multipart/form-data" action="{{ $editing ? route('admin.whatsapp.content.update',$editing) : route('admin.whatsapp.content.store') }}" class="wa-form-grid">
@csrf @if($editing) @method('put') @endif
<div class="full"><label>Judul</label><input class="form-control" name="title" required value="{{ old('title',$editing?->title) }}"></div>
<div><label>Kategori</label><input class="form-control" name="category" required value="{{ old('category',$editing?->category ?? 'umum') }}"></div>
<div><label>Status</label><select class="form-select" name="status">@foreach(['draft'=>'Draf','ready'=>'Siap dipakai','archived'=>'Arsip'] as $value=>$label)<option value="{{ $value }}" @selected(old('status',$editing?->status ?? 'draft')===$value)>{{ $label }}</option>@endforeach</select></div>
<div class="full"><label>Layanan terkait (opsional)</label><select class="form-select" name="service_id"><option value="">Umum</option>@foreach($services as $service)<option value="{{ $service->id }}" @selected((string)old('service_id',$editing?->service_id)===(string)$service->id)>{{ $service->name }}</option>@endforeach</select></div>
<div class="full"><label>Isi pesan</label><textarea class="form-control" name="body" rows="10" required>{{ old('body',$editing?->body) }}</textarea><small>Boleh gunakan sapaan seperti {nama_pelanggan}.</small></div>
<div class="full"><label>Media opsional (maks. 20 MB)</label><input class="form-control" type="file" name="media" accept="image/*,.pdf,video/mp4,audio/mpeg">@if($editing?->media_path)<small>Media sudah tersimpan. Unggah baru untuk mengganti.</small>@endif</div>
<div class="full"><label>Jadwal konten opsional</label><input class="form-control" type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at',$editing?->scheduled_at?->format('Y-m-d\TH:i')) }}"></div>
<div class="full"><button class="btn btn-primary">{{ $editing ? 'Simpan perubahan' : 'Tambahkan' }}</button></div>
</form></section>
<section class="wa-card wa-span-8"><h2>Konten tersimpan</h2>
<form method="get" class="wa-form-grid"><div><label>Cari</label><input class="form-control" name="q" value="{{ request('q') }}"></div><div><label>Kategori</label><select class="form-select" name="category"><option value="">Semua</option>@foreach($categories as $category)<option @selected(request('category')===$category)>{{ $category }}</option>@endforeach</select></div><div class="d-flex align-items-end"><button class="btn btn-outline-primary">Filter</button></div></form>
<div class="wa-table-wrap"><table class="wa-table"><thead><tr><th>Konten</th><th>Kategori</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
@forelse($items as $item)<tr><td><strong>{{ $item->title }}</strong><br><small>{{ Str::limit($item->body,120) }}</small></td><td>{{ $item->category }}</td><td><span class="wa-status {{ $item->status }}">{{ $item->status }}</span></td><td><div class="wa-inline-actions"><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.whatsapp.content.index',['edit'=>$item->id]) }}">Ubah</a><form method="post" action="{{ route('admin.whatsapp.content.destroy',$item) }}" onsubmit="return confirm('Hapus konten ini?')">@csrf @method('delete')<button class="btn btn-sm btn-outline-danger">Hapus</button></form></div></td></tr>
@empty<tr><td colspan="4" class="wa-muted">Belum ada konten.</td></tr>@endforelse
</tbody></table></div>{{ $items->links() }}</section></div>
@endsection

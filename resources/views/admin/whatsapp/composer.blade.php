@extends('layouts.admin')
@section('title', 'Kirim WhatsApp')
@section('heading', 'Kirim WhatsApp')
@section('content')
@include('admin.whatsapp._nav')
<div class="wa-grid">
<section class="wa-card wa-span-5"><h2>Pesan personal / grup</h2>
<form method="post" action="{{ route('admin.whatsapp.composer.personal') }}" class="wa-form-grid">@csrf
<div><label>Kanal</label><select class="form-select" name="channel"><option value="personal">Personal</option><option value="group">Grup</option></select></div>
<div><label>Nomor / ID grup</label><input class="form-control" name="phone" required placeholder="628... atau 120363...@g.us"></div>
<div class="full"><label>Nama penerima</label><input class="form-control" name="recipient_name"></div>
<div class="full"><label>Ambil dari bank konten</label><select class="form-select content-picker"><option value="">Tulis sendiri</option>@foreach($contents as $content)<option data-body="{{ $content->body }}" data-media="{{ $content->media_path ? url(Storage::disk('public')->url($content->media_path)) : '' }}">{{ $content->title }}</option>@endforeach</select></div>
<div class="full"><label>Isi pesan</label><textarea class="form-control message-body" name="body" rows="8" required></textarea></div>
<div class="full"><label>URL media opsional</label><input class="form-control media-url" type="url" name="media_url"></div>
<div class="full"><label>Jadwal opsional</label><input class="form-control" type="datetime-local" name="scheduled_at"></div>
<div class="full"><button class="btn btn-primary">Kirim ke antrean</button></div></form></section>

<section class="wa-card wa-span-7"><h2>Broadcast terkontrol</h2><p class="wa-muted">Pilih penerima, periksa kembali isi, lalu kirim. Kontak tanpa izin promosi otomatis dilewati saat antrean dibuat.</p>
<form method="post" action="{{ route('admin.whatsapp.composer.broadcast.send') }}" class="wa-form-grid">@csrf
<div class="full"><label>Nama broadcast</label><input class="form-control" name="name" required></div>
<div class="full"><label>Preset penerima</label><select class="form-select" name="preset_id"><option value="">Tanpa preset</option>@foreach($presets as $preset)<option value="{{ $preset->id }}">{{ $preset->name }}</option>@endforeach</select></div>
<div><label>Kontak</label><select class="form-select" name="contact_ids[]" multiple size="8">@foreach($contacts as $contact)<option value="{{ $contact->id }}">{{ $contact->name ?: $contact->phone }} — {{ $contact->phone }}</option>@endforeach</select></div>
<div><label>Grup</label><select class="form-select" name="group_ids[]" multiple size="8">@foreach($groups as $group)<option value="{{ $group->id }}">{{ $group->name }}</option>@endforeach</select></div>
<div class="full"><label>Label kontak</label><select class="form-select" name="label_ids[]" multiple size="5">@foreach($labels as $label)<option value="{{ $label->id }}">{{ $label->name }}</option>@endforeach</select></div>
<div class="full"><label>Ambil dari bank konten</label><select class="form-select content-picker"><option value="">Tulis sendiri</option>@foreach($contents as $content)<option data-body="{{ $content->body }}" data-media="{{ $content->media_path ? url(Storage::disk('public')->url($content->media_path)) : '' }}">{{ $content->title }}</option>@endforeach</select></div>
<div class="full"><label>Isi pesan</label><textarea class="form-control message-body" name="body" rows="8" required></textarea></div>
<div><label>URL media opsional</label><input class="form-control media-url" type="url" name="media_url"></div><div><label>Jadwal opsional</label><input class="form-control" type="datetime-local" name="scheduled_at"></div>
<div class="full"><button class="btn btn-primary">Tinjau penerima</button></div></form></section>

<section class="wa-card wa-span-12"><h2>Preset penerima</h2><form method="post" action="{{ route('admin.whatsapp.presets.store') }}" class="wa-form-grid">@csrf
<div><label>Nama preset</label><input class="form-control" name="name" required></div><div><label>Kontak</label><select class="form-select" name="contact_ids[]" multiple size="5">@foreach($contacts as $contact)<option value="{{ $contact->id }}">{{ $contact->name ?: $contact->phone }}</option>@endforeach</select></div><div><label>Label</label><select class="form-select" name="label_ids[]" multiple size="5">@foreach($labels as $label)<option value="{{ $label->id }}">{{ $label->name }}</option>@endforeach</select></div><div><label>Grup</label><select class="form-select" name="group_ids[]" multiple size="5">@foreach($groups as $group)<option value="{{ $group->id }}">{{ $group->name }}</option>@endforeach</select></div><div class="d-flex align-items-end"><button class="btn btn-outline-primary">Simpan preset</button></div></form>
<div class="wa-inline-actions">@foreach($presets as $preset)<form method="post" action="{{ route('admin.whatsapp.presets.destroy',$preset) }}">@csrf @method('delete')<button class="btn btn-sm btn-outline-danger">{{ $preset->name }} ×</button></form>@endforeach</div></section>
</div>
<script>document.querySelectorAll('.content-picker').forEach(function(picker){picker.addEventListener('change',function(){var option=this.options[this.selectedIndex],form=this.closest('form');if(option.dataset.body)form.querySelector('.message-body').value=option.dataset.body;if(option.dataset.media)form.querySelector('.media-url').value=option.dataset.media;});});</script>
@endsection

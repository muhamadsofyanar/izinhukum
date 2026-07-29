@extends('layouts.admin')
@section('title', 'Percakapan WhatsApp')
@section('heading', $conversation->display_name ?: $conversation->phone)
@section('header_action')<a class="btn btn-outline-primary" href="{{ route('admin.whatsapp.inbox.index') }}">Kembali ke Inbox</a>@endsection
@section('content')
@include('admin.whatsapp._nav')
<div class="wa-grid">
<section class="wa-card wa-span-8">
<div class="wa-conversation">
@forelse($conversation->messages as $message)
<div class="wa-bubble {{ $message->direction }}">
@if($message->body)<div class="wa-message-body">{{ $message->body }}</div>@endif
@if($message->media_url)<a href="{{ $message->media_url }}" target="_blank" rel="noopener">Buka media ↗</a>@endif
<small>{{ $message->direction === 'inbound' ? 'Masuk' : 'Keluar' }} · {{ $message->created_at?->format('d/m/Y H:i:s') }} · {{ $message->status }}</small>
</div>
@empty<p class="wa-muted">Belum ada pesan.</p>@endforelse
</div>
<hr>
<h3>Balas</h3>
<form method="post" action="{{ route('admin.whatsapp.inbox.reply',$conversation) }}" class="wa-form-grid">@csrf
<div><label>Tipe</label><select class="form-select" name="message_type"><option value="text">Teks</option><option value="image">Gambar</option><option value="document">Dokumen</option><option value="video">Video</option><option value="audio">Audio</option><option value="media">Media</option></select></div>
<div><label>URL media opsional</label><input class="form-control" type="url" name="media_url"></div>
<div class="full"><label>Isi balasan</label><textarea class="form-control" name="body" rows="5"></textarea></div>
<div class="full"><button class="btn btn-primary">Kirim ke antrean</button></div>
</form>
</section>
<section class="wa-card wa-span-4">
<h2>Data percakapan</h2>
<p><strong>Nomor:</strong><br><code>{{ $conversation->phone }}</code></p>
<p><strong>Tipe:</strong> {{ $conversation->contact_type }}</p>
<p><strong>Order:</strong> {{ $conversation->serviceOrder?->order_number ?: '-' }}</p>
<p><strong>Proposal:</strong> {{ $conversation->inquiry?->reference ?: '-' }}</p>
<p><strong>Mitra:</strong> {{ $conversation->partner?->name ?: '-' }}</p>
<form method="post" action="{{ route('admin.whatsapp.inbox.update',$conversation) }}" class="wa-form-grid">@csrf @method('put')
<div class="full"><label>Status</label><select class="form-select" name="status">@foreach(['open'=>'Terbuka','pending'=>'Menunggu admin','waiting_customer'=>'Menunggu pelanggan','closed'=>'Selesai'] as $key=>$label)<option value="{{ $key }}" @selected($conversation->status===$key)>{{ $label }}</option>@endforeach</select></div>
<div class="full"><label>Ditugaskan kepada</label><select class="form-select" name="assigned_to"><option value="">Belum ditugaskan</option>@foreach($admins as $admin)<option value="{{ $admin->id }}" @selected($conversation->assigned_to===$admin->id)>{{ $admin->name }}</option>@endforeach</select></div>
<div class="full"><label>Label, pisahkan koma</label><input class="form-control" name="labels" value="{{ implode(', ', $conversation->labels ?? []) }}"></div>
<div class="full"><label><input type="checkbox" name="is_ai_blocked" value="1" @checked($conversation->is_ai_blocked)> Blokir AI lokal</label></div>
<div class="full"><button class="btn btn-outline-primary">Simpan data</button></div>
</form>
<hr>
<h3>Blacklist AI provider</h3>
<div class="wa-inline-actions">
<form method="post" action="{{ route('admin.whatsapp.inbox.ai-blacklist',$conversation) }}">@csrf<input type="hidden" name="blocked" value="1"><button class="btn btn-sm btn-outline-danger">Tambahkan blacklist</button></form>
<form method="post" action="{{ route('admin.whatsapp.inbox.ai-blacklist',$conversation) }}">@csrf<input type="hidden" name="blocked" value="0"><button class="btn btn-sm btn-outline-primary">Hapus blacklist</button></form>
</div>
</section>
</div>
@endsection

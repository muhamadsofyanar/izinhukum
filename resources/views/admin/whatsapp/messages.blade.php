@extends('layouts.admin')
@section('title', 'Riwayat WhatsApp')
@section('heading', 'Pesan WhatsApp')
@section('content')
@include('admin.whatsapp._nav')
<div class="wa-grid">
<section class="wa-card wa-span-5">
    <h2>Kirim pesan manual</h2>
    <form method="post" action="{{ route('admin.whatsapp.messages.store') }}" class="wa-form-grid">
        @csrf
        <div><label>Nomor atau ID grup</label><input class="form-control" name="phone" required></div>
        <div><label>Nama penerima</label><input class="form-control" name="recipient_name"></div>
        <div><label>Kanal</label><select class="form-select" name="channel"><option value="personal">Personal</option><option value="group">Grup</option></select></div>
        <div><label>Perangkat</label><select class="form-select" name="device_alias"><option value="transaction">Transaksi</option><option value="support">Support</option><option value="partner">Mitra</option><option value="campaign">Campaign</option><option value="default">Default</option></select></div>
        <div><label>Tipe pesan</label><select class="form-select" name="message_type"><option value="text">Teks</option><option value="image">Gambar</option><option value="document">Dokumen</option><option value="video">Video</option><option value="audio">Audio</option><option value="media">Media lain</option></select></div>
        <div><label>Jadwal opsional</label><input class="form-control" type="datetime-local" name="scheduled_at"></div>
        <div class="full"><label>Isi pesan</label><textarea class="form-control" name="body" rows="6"></textarea></div>
        <div class="full"><label>URL media opsional</label><input class="form-control" type="url" name="media_url" placeholder="Gunakan URL aman. Jangan gunakan tautan publik permanen untuk dokumen sensitif."></div>
        <div class="full"><button class="btn btn-primary" type="submit">Kirim ke antrean</button></div>
    </form>
</section>
<section class="wa-card wa-span-7">
    <h2>Filter riwayat</h2>
    <form method="get" class="wa-form-grid">
        <div><label>Pencarian</label><input class="form-control" name="q" value="{{ $search }}" placeholder="Nomor, nama, isi, atau message ID"></div>
        <div><label>Status</label><select class="form-select" name="status"><option value="">Semua</option>@foreach(['queued','scheduled','processing','accepted','sent','retrying','failed','received','cancelled'] as $item)<option value="{{ $item }}" @selected($status===$item)>{{ $item }}</option>@endforeach</select></div>
        <div><label>Arah</label><select class="form-select" name="direction"><option value="">Semua</option><option value="inbound" @selected($direction==='inbound')>Masuk</option><option value="outbound" @selected($direction==='outbound')>Keluar</option></select></div>
        <div class="d-flex align-items-end"><button class="btn btn-outline-primary" type="submit">Terapkan</button></div>
    </form>
</section>
<section class="wa-card wa-span-12">
<div class="wa-table-wrap"><table class="wa-table"><thead><tr><th>Waktu</th><th>Arah</th><th>Tujuan</th><th>Isi</th><th>Status</th><th>Percobaan</th><th>Aksi</th></tr></thead><tbody>
@forelse($messages as $message)
<tr>
<td>{{ $message->created_at?->format('d/m/Y H:i:s') }}</td>
<td>{{ $message->direction === 'inbound' ? 'Masuk' : 'Keluar' }}<br><small>{{ $message->channel }}</small></td>
<td>{{ $message->recipient_name ?: '-' }}<br><code>{{ $message->phone }}</code></td>
<td class="wa-message-body">{{ \Illuminate\Support\Str::limit($message->body, 180) }}@if($message->media_url)<br><a href="{{ $message->media_url }}" target="_blank" rel="noopener">Media ↗</a>@endif @if($message->last_error)<br><small class="text-danger">{{ $message->last_error }}</small>@endif</td>
<td><span class="wa-status {{ $message->status }}">{{ $message->status }}</span>@if($message->provider_message_id)<br><small>{{ $message->provider_message_id }}</small>@endif</td>
<td>{{ $message->attempts }}</td>
<td><div class="wa-inline-actions">@if(in_array($message->status,['failed','retrying']))<form method="post" action="{{ route('admin.whatsapp.messages.retry',$message) }}">@csrf<button class="btn btn-sm btn-outline-primary">Coba lagi</button></form>@endif @if(in_array($message->status,['queued','scheduled','retrying']))<form method="post" action="{{ route('admin.whatsapp.messages.cancel',$message) }}">@csrf<button class="btn btn-sm btn-outline-danger">Batalkan</button></form>@endif</div></td>
</tr>
@empty<tr><td colspan="7" class="wa-muted">Belum ada pesan sesuai filter.</td></tr>@endforelse
</tbody></table></div>
{{ $messages->links() }}
</section>
</div>
@endsection

@extends('layouts.admin')
@section('title', 'Inbox WhatsApp')
@section('heading', 'Inbox WhatsApp')
@section('content')
@include('admin.whatsapp._nav')
<section class="wa-card">
<form method="get" class="wa-form-grid mb-3">
<div><label>Pencarian</label><input class="form-control" name="q" value="{{ $search }}" placeholder="Nama, nomor, atau JID grup"></div>
<div><label>Status</label><select class="form-select" name="status"><option value="">Semua</option>@foreach(['open'=>'Terbuka','pending'=>'Menunggu admin','waiting_customer'=>'Menunggu pelanggan','closed'=>'Selesai'] as $key=>$label)<option value="{{ $key }}" @selected($status===$key)>{{ $label }}</option>@endforeach</select></div>
<div><label>Kanal</label><select class="form-select" name="channel"><option value="">Personal dan grup</option><option value="personal" @selected($channel==='personal')>Personal</option><option value="group" @selected($channel==='group')>Grup</option></select></div>
<div class="d-flex align-items-end"><button class="btn btn-outline-primary">Terapkan filter</button></div>
</form>
<div class="wa-table-wrap"><table class="wa-table"><thead><tr><th>Kontak atau grup</th><th>Kanal</th><th>Status</th><th>Belum dibaca</th><th>Order</th><th>Pesan terakhir</th><th></th></tr></thead><tbody>
@forelse($conversations as $conversation)
<tr>
<td><strong>{{ $conversation->display_name ?: ($conversation->channel === 'group' ? 'Grup WhatsApp' : 'Nomor belum dikenal') }}</strong><br><code>{{ $conversation->phone }}</code></td>
<td><span class="wa-status {{ $conversation->channel === 'group' ? 'active' : '' }}">{{ $conversation->channel === 'group' ? 'Grup' : 'Personal' }}</span><br><small>{{ $conversation->contact_type }}</small></td>
<td><span class="wa-status {{ $conversation->status }}">{{ $conversation->status }}</span>@if($conversation->is_ai_blocked && $conversation->channel !== 'group')<br><small>AI diblokir</small>@endif</td>
<td>{{ $conversation->unread_count }}</td>
<td>{{ $conversation->channel === 'group' ? '-' : ($conversation->serviceOrder?->order_number ?: '-') }}</td>
<td>{{ $conversation->last_message_at?->format('d/m/Y H:i') ?: '-' }}</td>
<td><a class="btn btn-sm btn-primary" href="{{ route('admin.whatsapp.inbox.show',$conversation) }}">Buka</a></td>
</tr>
@empty<tr><td colspan="7" class="wa-muted">Belum ada percakapan. Pasang URL webhook StarSender dan aktifkan feature flag Inbox. Pesan grup juga memerlukan Add-On Webhook Group.</td></tr>@endforelse
</tbody></table></div>
{{ $conversations->links() }}
</section>
@endsection

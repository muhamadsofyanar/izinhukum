@extends('layouts.admin')
@section('title', 'Detail Campaign')
@section('heading', $campaign->name)
@section('header_action')<a class="btn btn-outline-primary" href="{{ route('admin.whatsapp.campaigns.index') }}">Kembali</a>@endsection
@section('content')
@include('admin.whatsapp._nav')
<div class="wa-grid">
<section class="wa-card wa-span-4">
<h2>Ringkasan</h2>
<p><strong>Status:</strong> <span class="wa-status {{ $campaign->status }}">{{ $campaign->status }}</span></p>
<p><strong>Template:</strong> {{ $campaign->template?->name ?: '-' }}</p>
<p><strong>Penerima:</strong> {{ number_format($campaign->recipient_count,0,',','.') }}</p>
<p><strong>Masuk antrean:</strong> {{ number_format($campaign->queued_count,0,',','.') }}</p>
<p><strong>Terkirim:</strong> {{ number_format($campaign->sent_count,0,',','.') }}</p>
<p><strong>Gagal:</strong> {{ number_format($campaign->failed_count,0,',','.') }}</p>
<p><strong>Jeda:</strong> {{ $campaign->delay_seconds }} detik</p>
<p><strong>Jadwal:</strong> {{ $campaign->scheduled_at?->format('d/m/Y H:i') ?: 'Segera setelah dijalankan' }}</p>
<div class="wa-inline-actions">
@if(in_array($campaign->status,['draft','scheduled','paused']))<form method="post" action="{{ route('admin.whatsapp.campaigns.dispatch',$campaign) }}">@csrf<button class="btn btn-primary">Jalankan campaign</button></form>@endif
@if(!in_array($campaign->status,['completed','cancelled']))<form method="post" action="{{ route('admin.whatsapp.campaigns.cancel',$campaign) }}">@csrf<button class="btn btn-outline-danger" onclick="return confirm('Batalkan penerima yang belum diproses?')">Batalkan</button></form>@endif
</div>
@if($campaign->notes)<hr><p class="wa-message-body">{{ $campaign->notes }}</p>@endif
</section>
<section class="wa-card wa-span-8 wa-recipient-list">
<h2>Daftar penerima</h2>
<div class="wa-table-wrap"><table class="wa-table"><thead><tr><th>Nama</th><th>Nomor</th><th>Status</th><th>Kesalahan</th></tr></thead><tbody>
@foreach($campaign->recipients as $recipient)<tr><td>{{ $recipient->name ?: '-' }}</td><td><code>{{ $recipient->phone }}</code></td><td><span class="wa-status {{ $recipient->status }}">{{ $recipient->status }}</span></td><td>{{ $recipient->error ?: '-' }}</td></tr>@endforeach
</tbody></table></div>
</section>
</div>
@endsection

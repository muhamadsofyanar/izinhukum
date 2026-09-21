@extends('layouts.admin')
@section('title', 'Konfirmasi Broadcast')
@section('heading', 'Konfirmasi Broadcast')
@section('content')
@include('admin.whatsapp._nav')
<div class="wa-grid">
<section class="wa-card wa-span-4"><h2>{{ $campaign->name }}</h2><p><strong>Isi pesan</strong></p><p class="wa-message-body">{{ $campaign->body }}</p>@if($campaign->media_path)<p><a href="{{ $campaign->media_path }}" target="_blank" rel="noopener">Periksa media ↗</a></p>@endif<p><strong>Jadwal:</strong> {{ $campaign->scheduled_at?->format('d/m/Y H:i') ?: 'Segera' }}</p>
<form method="post" action="{{ route('admin.whatsapp.composer.broadcast.confirm',$campaign) }}" onsubmit="return confirm('Kirim ke penerima yang memenuhi syarat?')">@csrf<input type="hidden" name="confirmation_token" value="{{ $confirmationToken }}"><button class="btn btn-primary">Konfirmasi {{ $campaign->recipients->where('status','pending')->count() }} penerima</button></form>
<p><a href="{{ route('admin.whatsapp.composer.index') }}">Batalkan dan kembali</a></p></section>
<section class="wa-card wa-span-8"><h2>Pratinjau penerima</h2><p><strong>{{ $campaign->recipients->where('status','pending')->count() }}</strong> memenuhi syarat · <strong>{{ $campaign->recipients->where('status','skipped')->count() }}</strong> dikecualikan</p>
<div class="wa-table-wrap"><table class="wa-table"><thead><tr><th>Penerima</th><th>Kanal</th><th>Keputusan</th><th>Alasan</th></tr></thead><tbody>@foreach($campaign->recipients as $recipient)<tr><td>{{ $recipient->name ?: '-' }}<br><code>{{ $recipient->phone }}</code></td><td>{{ $recipient->channel }}</td><td><span class="wa-status {{ $recipient->status }}">{{ $recipient->status === 'pending' ? 'Memenuhi syarat' : 'Dikecualikan' }}</span></td><td>{{ $recipient->error ?: '-' }}</td></tr>@endforeach</tbody></table></div></section>
</div>
@endsection

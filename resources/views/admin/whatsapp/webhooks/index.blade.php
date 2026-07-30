@extends('layouts.admin')
@section('title', 'Monitor Webhook')
@section('heading', 'Monitor Webhook WhatsApp')
@section('content')
@include('admin.whatsapp._nav')
<div class="wa-grid">
    @foreach(['total'=>'Total event','processed'=>'Selesai','pending'=>'Menunggu proses','failed'=>'Gagal'] as $key=>$label)<section class="wa-card wa-span-3 wa-stat"><strong>{{ number_format($stats[$key] ?? 0) }}</strong><span>{{ $label }}</span></section>@endforeach
    <section class="wa-card wa-span-12">
        <div class="wa-inline-actions"><a class="btn {{ $status===''?'btn-primary':'btn-outline-secondary' }}" href="{{ route('admin.whatsapp.webhooks.index') }}">Semua</a><a class="btn {{ $status==='processed'?'btn-primary':'btn-outline-secondary' }}" href="{{ route('admin.whatsapp.webhooks.index',['status'=>'processed']) }}">Selesai</a><a class="btn {{ $status==='pending'?'btn-primary':'btn-outline-secondary' }}" href="{{ route('admin.whatsapp.webhooks.index',['status'=>'pending']) }}">Pending</a><a class="btn {{ $status==='failed'?'btn-primary':'btn-outline-secondary' }}" href="{{ route('admin.whatsapp.webhooks.index',['status'=>'failed']) }}">Gagal</a></div>
        <p class="wa-muted mt-3">Webhook terakhir: {{ $stats['latest'] ? \Carbon\Carbon::parse($stats['latest'])->format('d/m/Y H:i:s') : 'belum ada' }}</p>
        <div class="wa-table-wrap"><table class="wa-table"><thead><tr><th>Waktu</th><th>Jenis</th><th>Nomor/JID</th><th>Provider ID</th><th>Status</th><th>Error</th><th></th></tr></thead><tbody>@forelse($events as $event)<tr><td>{{ $event->created_at?->format('d/m/Y H:i:s') }}</td><td>{{ $event->event_type }}</td><td><code>{{ $event->phone ?: '-' }}</code></td><td><code>{{ $event->provider_message_id ?: '-' }}</code></td><td><span class="wa-status {{ $event->processed?'ok':($event->processing_error?'bad':'warn') }}">{{ $event->processed?'processed':($event->processing_error?'failed':'pending') }}</span></td><td><small>{{ $event->processing_error ?: '-' }}</small></td><td>@if(!$event->processed || $event->processing_error)<form method="post" action="{{ route('admin.whatsapp.webhooks.retry',$event) }}">@csrf<button class="btn btn-sm btn-outline-primary">Retry</button></form>@endif</td></tr>@empty<tr><td colspan="7" class="wa-muted">Belum ada webhook masuk. Pastikan URL webhook sudah dipasang pada StarSender.</td></tr>@endforelse</tbody></table></div>
        {{ $events->links() }}
    </section>
</div>
@endsection

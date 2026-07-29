@extends('layouts.admin')
@section('title', 'Campaign WhatsApp')
@section('heading', 'Campaign WhatsApp')
@section('content')
@include('admin.whatsapp._nav')
<div class="alert alert-warning">Campaign hanya untuk penerima yang memberi persetujuan promosi. Nomor yang memilih STOP akan dilewati otomatis.</div>
<div class="wa-grid">
<section class="wa-card wa-span-5">
<h2>Campaign baru</h2>
@if($templates->isEmpty())<div class="alert alert-info">Buat atau tandai satu template sebagai <strong>Promosi</strong> terlebih dahulu.</div>@endif
<form method="post" action="{{ route('admin.whatsapp.campaigns.store') }}" class="wa-form-grid">@csrf
<div class="full"><label>Nama campaign</label><input class="form-control" name="name" required></div>
<div class="full"><label>Template promosi</label><select class="form-select" name="template_id" required>@foreach($templates as $template)<option value="{{ $template->id }}">{{ $template->name }}</option>@endforeach</select></div>
<div class="full"><label>Penerima</label><textarea class="form-control" name="recipients" rows="9" placeholder="081234567890,Budi&#10;081298765432,Siti" required></textarea><small class="wa-muted">Satu baris per penerima: nomor,nama. Maksimal 500 penerima per campaign. Pecah daftar besar menjadi beberapa campaign.</small></div>
<div><label>Jadwal opsional</label><input class="form-control" type="datetime-local" name="scheduled_at"></div>
<div><label>Jeda antar pesan</label><input class="form-control" type="number" name="delay_seconds" value="30" min="30" max="3600" required></div>
<div><label>Mode rotator</label><select class="form-select" name="rotator_mode"><option value="round_robin">Round robin</option><option value="batch">Batch</option></select></div>
<div><label><input type="checkbox" name="use_rotator" value="1"> Gunakan rotator</label></div>
<div class="full"><label>Catatan internal</label><textarea class="form-control" name="notes" rows="3"></textarea></div>
<div class="full"><button class="btn btn-primary" @disabled($templates->isEmpty())>Buat draf</button></div>
</form>
</section>
<section class="wa-card wa-span-7">
<h2>Riwayat campaign</h2>
<div class="wa-table-wrap"><table class="wa-table"><thead><tr><th>Nama</th><th>Template</th><th>Penerima</th><th>Status</th><th>Jadwal</th><th></th></tr></thead><tbody>
@forelse($campaigns as $campaign)
<tr><td><strong>{{ $campaign->name }}</strong><br><small>{{ $campaign->created_at?->format('d/m/Y H:i') }}</small></td><td>{{ $campaign->template?->name ?: '-' }}</td><td>{{ number_format($campaign->recipients_count,0,',','.') }}</td><td><span class="wa-status {{ $campaign->status }}">{{ $campaign->status }}</span>@if($campaign->use_rotator)<br><small>Rotator {{ $campaign->rotator_mode }}</small>@endif</td><td>{{ $campaign->scheduled_at?->format('d/m/Y H:i') ?: '-' }}</td><td><a class="btn btn-sm btn-primary" href="{{ route('admin.whatsapp.campaigns.show',$campaign) }}">Detail</a></td></tr>
@empty<tr><td colspan="6" class="wa-muted">Belum ada campaign.</td></tr>@endforelse
</tbody></table></div>
{{ $campaigns->links() }}
</section>
</div>
@endsection

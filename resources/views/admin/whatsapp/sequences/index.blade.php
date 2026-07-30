@extends('layouts.admin')
@section('title', 'Sequence Follow-up')
@section('heading', 'Sequence Follow-up')
@section('content')
@include('admin.whatsapp._nav')
<div class="wa-grid">
    <section class="wa-card wa-span-8">
        <h2>Daftar sequence</h2>
        <div class="wa-table-wrap"><table class="wa-table"><thead><tr><th>Nama</th><th>Target</th><th>Langkah</th><th>Enrollment</th><th>Status</th><th></th></tr></thead><tbody>@forelse($sequences as $sequence)<tr><td><strong>{{ $sequence->name }}</strong><br><small>{{ $sequence->description }}</small></td><td>{{ $sequence->audience_type }}</td><td>{{ $sequence->steps_count }}</td><td>{{ $sequence->enrollments_count }}</td><td><span class="wa-status {{ $sequence->is_active?'ok':'warn' }}">{{ $sequence->is_active?'Aktif':'Draft' }}</span></td><td><a class="btn btn-sm btn-primary" href="{{ route('admin.whatsapp.sequences.show',$sequence) }}">Kelola</a></td></tr>@empty<tr><td colspan="6" class="wa-muted">Belum ada sequence.</td></tr>@endforelse</tbody></table></div>
        {{ $sequences->links() }}
    </section>
    <aside class="wa-card wa-span-4">
        <h2>Buat sequence</h2>
        <form method="post" action="{{ route('admin.whatsapp.sequences.store') }}" class="wa-form-grid">
            @csrf
            <div class="full"><label>Nama</label><input class="form-control" name="name" required placeholder="Follow-up Pendirian PT"></div>
            <div class="full"><label>Deskripsi</label><textarea class="form-control" name="description" rows="3"></textarea></div>
            <div><label>Jenis target</label><select class="form-select" name="audience_type"><option value="contact">Kontak personal</option><option value="label">Kontak berdasarkan label</option><option value="group_preset">Kategori grup</option></select></div>
            <div><label>Perangkat</label><select class="form-select" name="device_alias"><option value="support">Support</option><option value="transaction">Transaksi</option><option value="campaign">Campaign</option></select></div>
            <div><label>Jeda antargrup (detik)</label><input class="form-control" type="number" name="group_interval_seconds" min="1" max="3600" value="10" required></div>
            <div class="full"><label class="wa-confirm-box"><input type="checkbox" name="stop_on_reply" value="1" checked> Berhenti otomatis saat kontak membalas.</label></div>
            <div class="full"><label class="wa-confirm-box"><input type="checkbox" name="stop_on_deal" value="1" checked> Berhenti otomatis saat lead ditandai deal.</label></div>
            <div class="full"><button class="btn btn-primary">Buat sequence</button></div>
        </form>
    </aside>
</div>
@endsection

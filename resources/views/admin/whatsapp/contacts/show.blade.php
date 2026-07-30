@extends('layouts.admin')
@section('title', 'Detail Kontak CRM')
@section('heading', $contact->name ?: $contact->phone)
@section('content')
@include('admin.whatsapp._nav')
<div class="wa-grid">
    <section class="wa-card wa-span-4">
        <h2>Profil kontak</h2>
        <form method="post" action="{{ route('admin.whatsapp.contacts.update',$contact) }}" class="wa-form-grid">
            @csrf @method('put')
            <div class="full"><label>Nomor WhatsApp</label><input class="form-control" value="{{ $contact->phone }}" disabled></div>
            <div class="full"><label>Nama</label><input class="form-control" name="name" value="{{ old('name',$contact->name) }}"></div>
            <div><label>Email</label><input class="form-control" type="email" name="email" value="{{ old('email',$contact->email) }}"></div>
            <div><label>Perusahaan</label><input class="form-control" name="company" value="{{ old('company',$contact->company) }}"></div>
            <div><label>Sumber</label><input class="form-control" name="source" value="{{ old('source',$contact->source) }}" required></div>
            <div><label>Status</label><select class="form-select" name="status">@foreach(['active'=>'Aktif','inactive'=>'Tidak aktif','blocked'=>'Diblokir'] as $key=>$text)<option value="{{ $key }}" @selected($contact->status===$key)>{{ $text }}</option>@endforeach</select></div>
            <div><label>Tahap</label><select class="form-select" name="lifecycle_stage">@foreach(['contact'=>'Kontak','lead'=>'Lead','customer'=>'Klien','former_customer'=>'Mantan klien'] as $key=>$text)<option value="{{ $key }}" @selected($contact->lifecycle_stage===$key)>{{ $text }}</option>@endforeach</select></div>
            <div><label>Layanan</label><input class="form-control" name="service_interest" value="{{ old('service_interest',$contact->service_interest) }}"></div>
            <div><label>Admin</label><select class="form-select" name="assigned_to"><option value="">Belum ditentukan</option>@foreach($admins as $admin)<option value="{{ $admin->id }}" @selected($contact->assigned_to===$admin->id)>{{ $admin->name }}</option>@endforeach</select></div>
            <div><label>Follow-up berikutnya</label><input class="form-control" type="datetime-local" name="next_follow_up_at" value="{{ $contact->next_follow_up_at?->format('Y-m-d\TH:i') }}"></div>
            <div class="full"><label class="wa-confirm-box"><input type="checkbox" name="is_opted_out" value="1" @checked($contact->is_opted_out)> Kontak meminta berhenti menerima pesan.</label></div>
            <div class="full"><button class="btn btn-primary">Simpan profil</button></div>
        </form>
    </section>

    <section class="wa-card wa-span-4">
        <h2>Label kontak</h2>
        <form method="post" action="{{ route('admin.whatsapp.contacts.labels',$contact) }}">
            @csrf @method('put')
            <div class="wa-label-picker">@foreach($labels as $label)<label><input type="checkbox" name="label_ids[]" value="{{ $label->id }}" @checked($contact->labels->contains('id',$label->id))><span class="wa-label" style="--label-color:{{ $label->color }}">{{ $label->name }}</span></label>@endforeach</div>
            <button class="btn btn-outline-primary mt-3">Simpan label</button>
        </form>
        <hr>
        <h3>Kirim pesan cepat</h3>
        <form method="post" action="{{ route('admin.whatsapp.contacts.send',$contact) }}" class="wa-form-grid">
            @csrf
            <div class="full"><label>Isi pesan</label><textarea class="form-control" name="body" rows="5" required></textarea></div>
            <div class="full"><label>Perangkat</label><select class="form-select" name="device_alias"><option value="support">Support</option><option value="transaction">Transaksi</option><option value="campaign">Campaign</option></select></div>
            <div class="full"><button class="btn btn-primary">Masukkan antrean</button></div>
        </form>
    </section>

    <section class="wa-card wa-span-4">
        <h2>Sequence follow-up</h2>
        <form method="post" action="{{ route('admin.whatsapp.contacts.sequences.enroll',$contact) }}" class="wa-form-grid">
            @csrf
            <div class="full"><label>Pilih sequence aktif</label><select class="form-select" name="sequence_id" required><option value="">Pilih</option>@foreach($sequences as $sequence)<option value="{{ $sequence->id }}">{{ $sequence->name }}</option>@endforeach</select></div>
            <div class="full"><button class="btn btn-outline-primary">Masukkan ke sequence</button></div>
        </form>
        <div class="wa-table-wrap mt-3"><table class="wa-table"><thead><tr><th>Sequence</th><th>Status</th><th>Langkah</th><th>Berikutnya</th></tr></thead><tbody>@forelse($contact->sequenceEnrollments as $enrollment)<tr><td>{{ $enrollment->sequence?->name }}</td><td><span class="wa-status {{ $enrollment->status }}">{{ $enrollment->status }}</span></td><td>{{ $enrollment->current_step }}</td><td>{{ $enrollment->next_run_at?->format('d/m/Y H:i') ?: '-' }}</td></tr>@empty<tr><td colspan="4" class="wa-muted">Belum ada sequence.</td></tr>@endforelse</tbody></table></div>
    </section>

    <section class="wa-card wa-span-12">
        <h2>Lead dan proses penjualan</h2>
        <details class="mb-3"><summary><strong>Jadikan kontak ini sebagai lead baru</strong></summary>
            <form method="post" action="{{ route('admin.whatsapp.leads.store') }}" class="wa-form-grid mt-3">
                @csrf
                <input type="hidden" name="contact_id" value="{{ $contact->id }}">
                <input type="hidden" name="stage" value="new">
                <input type="hidden" name="probability" value="10">
                <div><label>Judul lead</label><input class="form-control" name="title" value="{{ $contact->service_interest ? $contact->service_interest.' · '.($contact->name ?: $contact->phone) : 'Lead '.($contact->name ?: $contact->phone) }}" required></div>
                <div><label>Sumber</label><input class="form-control" name="source" value="{{ $contact->source ?: 'whatsapp' }}" required></div>
                <div><label>Layanan</label><input class="form-control" name="service_interest" value="{{ $contact->service_interest }}"></div>
                <div><label>Nilai estimasi</label><input class="form-control" type="number" min="0" name="estimated_value" value="0"></div>
                <div><label>Admin</label><select class="form-select" name="assigned_to"><option value="">Belum ditentukan</option>@foreach($admins as $admin)<option value="{{ $admin->id }}" @selected($contact->assigned_to===$admin->id)>{{ $admin->name }}</option>@endforeach</select></div>
                <div><label>Follow-up</label><input class="form-control" type="datetime-local" name="next_follow_up_at"></div>
                <div class="full"><label>Catatan</label><textarea class="form-control" name="notes" rows="3"></textarea></div>
                <div class="full"><button class="btn btn-success">Buat lead</button></div>
            </form>
        </details>
        <div class="wa-table-wrap"><table class="wa-table"><thead><tr><th>Judul</th><th>Tahap</th><th>Layanan</th><th>Nilai estimasi</th><th>Follow-up</th><th>Order</th></tr></thead><tbody>@forelse($contact->leads as $lead)<tr><td>{{ $lead->title }}</td><td><span class="wa-status {{ $lead->stage==='deal'?'ok':'' }}">{{ $lead->stageLabel() }}</span></td><td>{{ $lead->service_interest ?: '-' }}</td><td>Rp {{ number_format((float)$lead->estimated_value,0,',','.') }}</td><td>{{ $lead->next_follow_up_at?->format('d/m/Y H:i') ?: '-' }}</td><td>{{ $lead->serviceOrder?->order_number ?: '-' }}</td></tr>@empty<tr><td colspan="6" class="wa-muted">Belum ada lead. Buat dari menu CRM.</td></tr>@endforelse</tbody></table></div>
    </section>

    <section class="wa-card wa-span-6">
        <h2>Dokumen</h2>
        <div class="wa-table-wrap"><table class="wa-table"><thead><tr><th>Nama</th><th>Kategori</th><th>Arsip</th><th>Verifikasi</th><th></th></tr></thead><tbody>@forelse($contact->documents as $document)<tr><td>{{ $document->name }}<br><small>{{ $document->original_name }}</small></td><td>{{ $document->category }}</td><td><span class="wa-status {{ $document->archive_status==='stored'?'ok':'warn' }}">{{ $document->archive_status }}</span></td><td>{{ $document->verification_status }}</td><td>@if($document->path)<a class="btn btn-sm btn-outline-primary" href="{{ route('admin.whatsapp.documents.download',$document) }}">Unduh</a>@endif</td></tr>@empty<tr><td colspan="5" class="wa-muted">Belum ada dokumen.</td></tr>@endforelse</tbody></table></div>
    </section>

    <section class="wa-card wa-span-6">
        <h2>Percakapan terkait</h2>
        <div class="wa-table-wrap"><table class="wa-table"><thead><tr><th>Nama</th><th>Kanal</th><th>Pesan terakhir</th><th></th></tr></thead><tbody>@forelse($contact->conversations as $conversation)<tr><td>{{ $conversation->display_name ?: $conversation->phone }}</td><td>{{ $conversation->channel }}</td><td>{{ $conversation->last_message_at?->format('d/m/Y H:i') ?: '-' }}</td><td><a class="btn btn-sm btn-primary" href="{{ route('admin.whatsapp.inbox.show',$conversation) }}">Buka</a></td></tr>@empty<tr><td colspan="4" class="wa-muted">Belum ada percakapan terhubung.</td></tr>@endforelse</tbody></table></div>
    </section>
</div>
@endsection

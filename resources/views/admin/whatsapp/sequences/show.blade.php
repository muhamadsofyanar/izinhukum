@extends('layouts.admin')
@section('title', 'Kelola Sequence')
@section('heading', $sequence->name)
@section('content')
@include('admin.whatsapp._nav')
<div class="wa-grid">
    <section class="wa-card wa-span-5">
        <h2>Pengaturan sequence</h2>
        <form method="post" action="{{ route('admin.whatsapp.sequences.update',$sequence) }}" class="wa-form-grid">
            @csrf @method('put')
            <div class="full"><label>Nama</label><input class="form-control" name="name" value="{{ $sequence->name }}" required></div>
            <div class="full"><label>Deskripsi</label><textarea class="form-control" name="description" rows="3">{{ $sequence->description }}</textarea></div>
            <div><label>Perangkat</label><select class="form-select" name="device_alias">@foreach(['support'=>'Support','transaction'=>'Transaksi','campaign'=>'Campaign','partner'=>'Mitra','default'=>'Default'] as $key=>$label)<option value="{{ $key }}" @selected($sequence->device_alias===$key)>{{ $label }}</option>@endforeach</select></div>
            <div><label>Jeda antargrup (detik)</label><input class="form-control" type="number" name="group_interval_seconds" min="1" max="3600" value="{{ $sequence->group_interval_seconds ?: 10 }}" required><small class="wa-muted">Berlaku saat target berupa kategori grup.</small></div>
            <div class="full"><label class="wa-confirm-box"><input type="checkbox" name="stop_on_reply" value="1" @checked($sequence->stop_on_reply)> Berhenti saat target membalas.</label></div>
            <div class="full"><label class="wa-confirm-box"><input type="checkbox" name="stop_on_deal" value="1" @checked($sequence->stop_on_deal)> Berhenti saat lead deal.</label></div>
            <div class="full"><label class="wa-confirm-box"><input type="checkbox" name="is_active" value="1" @checked($sequence->is_active)> Aktifkan sequence.</label></div>
            <div class="full"><button class="btn btn-primary">Simpan pengaturan</button></div>
        </form>
    </section>

    <section class="wa-card wa-span-7">
        <h2>Tambah langkah pesan</h2>
        <form method="post" action="{{ route('admin.whatsapp.sequences.steps.store',$sequence) }}" class="wa-form-grid">
            @csrf
            <div><label>Nama langkah</label><input class="form-control" name="name" required placeholder="Follow-up hari ke-2"></div>
            <div><label>Template opsional</label><select class="form-select" name="template_id"><option value="">Tanpa template</option>@foreach($templates as $template)<option value="{{ $template->id }}">{{ $template->name }}</option>@endforeach</select></div>
            <div class="full"><label>Dokumen vault opsional</label><select class="form-select" name="crm_document_id"><option value="">Tanpa dokumen vault</option>@foreach($documents as $document)<option value="{{ $document->id }}">{{ $document->name }}{{ $document->original_name ? ' · '.$document->original_name : '' }}</option>@endforeach</select><small class="wa-muted">Dokumen privat memperoleh tautan sementara hanya saat langkah dikirim. Jangan pilih bersamaan dengan template.</small></div>
            <div><label>Jeda</label><input class="form-control" type="number" name="delay_value" min="0" value="0" required></div>
            <div><label>Satuan</label><select class="form-select" name="delay_unit"><option value="minute">Menit</option><option value="hour">Jam</option><option value="day" selected>Hari</option></select></div>
            <div><label>Jam kirim opsional</label><input class="form-control" type="time" name="send_time"></div>
            <div><label>Tipe pesan</label><select class="form-select" name="message_type"><option value="text">Teks</option><option value="image">Gambar</option><option value="document">Dokumen</option><option value="video">Video</option><option value="audio">Audio</option></select></div>
            <div class="full"><label>Isi pesan</label><textarea class="form-control" name="body" rows="5" placeholder="Halo {{nama}}, ..."></textarea><small class="wa-muted">Variabel: @{{nama}}, @{{nomor_whatsapp}}, @{{layanan}}, @{{perusahaan}}</small></div>
            <div class="full"><label>URL media opsional</label><input class="form-control" name="media_url" placeholder="https://..."></div>
            <div class="full"><button class="btn btn-outline-primary">Tambahkan langkah</button></div>
        </form>
    </section>

    <section class="wa-card wa-span-12">
        <h2>Urutan langkah</h2>
        <div class="wa-sequence-timeline">@forelse($sequence->steps as $step)<article><div class="wa-step-number">{{ $step->position }}</div><div><strong>{{ $step->name }}</strong><br><span class="wa-muted">Jeda {{ $step->delay_value }} {{ $step->delay_unit }}{{ $step->send_time?' · jam '.substr($step->send_time,0,5):'' }} · {{ $step->message_type }}</span><div class="wa-message-body mt-2">{{ $step->body ?: ($step->template?->name ? 'Template: '.$step->template->name : '-') }}</div>@if($step->document)<small>Dokumen vault: {{ $step->document->name }}</small>@elseif($step->media_url)<small>{{ $step->media_url }}</small>@endif</div><form method="post" action="{{ route('admin.whatsapp.sequences.steps.destroy',[$sequence,$step]) }}" onsubmit="return confirm('Hapus langkah ini?')">@csrf @method('delete')<button class="btn btn-sm btn-outline-danger">Hapus</button></form></article>@empty<p class="wa-muted">Belum ada langkah.</p>@endforelse</div>
    </section>

    <section class="wa-card wa-span-5">
        <h2>Tambahkan target</h2>
        <form method="post" action="{{ route('admin.whatsapp.sequences.enroll',$sequence) }}" class="wa-form-grid">
            @csrf
            <div class="full"><label>Satu kontak</label><select class="form-select" name="contact_id"><option value="">Tidak dipilih</option>@foreach($contacts as $contact)<option value="{{ $contact->id }}">{{ $contact->name ?: $contact->phone }} · {{ $contact->phone }}</option>@endforeach</select></div>
            <div class="full"><label>Semua kontak dengan label</label><select class="form-select" name="label_id"><option value="">Tidak dipilih</option>@foreach($labels as $label)<option value="{{ $label->id }}">{{ $label->name }}</option>@endforeach</select></div>
            <div class="full"><label>Kategori grup</label><select class="form-select" name="group_preset_id"><option value="">Tidak dipilih</option>@foreach($groupPresets as $preset)<option value="{{ $preset->id }}">{{ $preset->name }} · {{ count((array)$preset->group_ids) }} grup</option>@endforeach</select></div>
            <div class="full"><button class="btn btn-primary">Masukkan target</button></div>
        </form>
    </section>

    <section class="wa-card wa-span-7">
        <h2>Enrollment</h2>
        <div class="wa-table-wrap"><table class="wa-table"><thead><tr><th>Target</th><th>Status</th><th>Langkah</th><th>Jadwal berikutnya</th><th>Aksi</th></tr></thead><tbody>@forelse($sequence->enrollments as $enrollment)<tr><td>{{ $enrollment->contact?->name ?: ($enrollment->groupPreset?->name ?: '-') }}<br><small>{{ $enrollment->contact?->phone }}</small></td><td><span class="wa-status {{ $enrollment->status }}">{{ $enrollment->status }}</span></td><td>{{ $enrollment->current_step }}/{{ $sequence->steps->count() }}</td><td>{{ $enrollment->next_run_at?->format('d/m/Y H:i') ?: '-' }}</td><td><form method="post" action="{{ route('admin.whatsapp.sequences.enrollments.action',$enrollment) }}" class="wa-inline-actions">@csrf @if($enrollment->status==='active')<button class="btn btn-sm btn-outline-secondary" name="action" value="pause">Pause</button>@elseif($enrollment->status==='paused')<button class="btn btn-sm btn-outline-primary" name="action" value="resume">Lanjut</button>@endif @if(!in_array($enrollment->status,['completed','stopped']))<button class="btn btn-sm btn-outline-danger" name="action" value="stop">Stop</button>@endif</form></td></tr>@empty<tr><td colspan="5" class="wa-muted">Belum ada target.</td></tr>@endforelse</tbody></table></div>
    </section>
</div>
@endsection

@extends('layouts.admin')
@section('title', 'Kontak CRM')
@section('heading', 'Kontak dan Label CRM')
@section('content')
@include('admin.whatsapp._nav')
<div class="wa-grid">
    @foreach(['total'=>'Total kontak','leads'=>'Calon klien','customers'=>'Klien','followups'=>'Follow-up dekat'] as $key=>$label)
        <section class="wa-card wa-span-3 wa-stat"><strong>{{ number_format($stats[$key] ?? 0) }}</strong><span>{{ $label }}</span></section>
    @endforeach

    <section class="wa-card wa-span-8">
        <h2>Daftar kontak</h2>
        <form method="get" class="wa-form-grid mb-3">
            <div><label>Pencarian</label><input class="form-control" name="q" value="{{ $search }}" placeholder="Nama, nomor, email, perusahaan"></div>
            <div><label>Label</label><select class="form-select" name="label_id"><option value="">Semua label</option>@foreach($labels as $label)<option value="{{ $label->id }}" @selected($labelId===$label->id)>{{ $label->name }}</option>@endforeach</select></div>
            <div><label>Tahap</label><select class="form-select" name="stage"><option value="">Semua tahap</option>@foreach(['contact'=>'Kontak','lead'=>'Lead','customer'=>'Klien','former_customer'=>'Mantan klien'] as $key=>$text)<option value="{{ $key }}" @selected($stage===$key)>{{ $text }}</option>@endforeach</select></div>
            <div><label>Sumber</label><select class="form-select" name="source"><option value="">Semua sumber</option>@foreach(['whatsapp'=>'WhatsApp','whatsapp_group'=>'Grup WhatsApp','website'=>'Website','ads'=>'Iklan','referral'=>'Referral','manual'=>'Manual'] as $key=>$text)<option value="{{ $key }}" @selected($source===$key)>{{ $text }}</option>@endforeach</select></div>
            <div class="full"><button class="btn btn-outline-primary">Terapkan filter</button> <a class="btn btn-outline-secondary" href="{{ route('admin.whatsapp.contacts.index') }}">Reset</a> <a class="btn btn-outline-success" href="{{ route('admin.whatsapp.contacts.export', request()->query()) }}">Ekspor CSV</a></div>
        </form>
        <div class="wa-table-wrap"><table class="wa-table"><thead><tr><th>Kontak</th><th>Label</th><th>Sumber</th><th>Tahap</th><th>Follow-up</th><th>Data</th><th></th></tr></thead><tbody>
        @forelse($contacts as $contact)
            <tr>
                <td><strong>{{ $contact->name ?: 'Nama belum diisi' }}</strong><br><code>{{ $contact->phone }}</code>@if($contact->email)<br><small>{{ $contact->email }}</small>@endif</td>
                <td><div class="wa-label-list">@forelse($contact->labels as $label)<span class="wa-label" style="--label-color:{{ $label->color }}">{{ $label->name }}</span>@empty<span class="wa-muted">-</span>@endforelse</div></td>
                <td>{{ $contact->source }}</td>
                <td><span class="wa-status {{ $contact->lifecycle_stage==='customer'?'ok':'' }}">{{ $contact->lifecycle_stage }}</span></td>
                <td>{{ $contact->next_follow_up_at?->format('d/m/Y H:i') ?: '-' }}</td>
                <td>{{ $contact->leads_count }} lead · {{ $contact->documents_count }} dokumen</td>
                <td><a class="btn btn-sm btn-primary" href="{{ route('admin.whatsapp.contacts.show',$contact) }}">Buka</a></td>
            </tr>
        @empty<tr><td colspan="7" class="wa-muted">Belum ada kontak. Jalankan <code>php artisan crm:backfill-contacts</code> atau tunggu pesan masuk baru.</td></tr>@endforelse
        </tbody></table></div>
        {{ $contacts->links() }}
    </section>

    <aside class="wa-card wa-span-4">
        <h2>Tambah kontak</h2>
        <form method="post" action="{{ route('admin.whatsapp.contacts.store') }}" class="wa-form-grid">
            @csrf
            <div class="full"><label>Nomor WhatsApp</label><input class="form-control" name="phone" required placeholder="62812..."></div>
            <div class="full"><label>Nama</label><input class="form-control" name="name"></div>
            <div><label>Email</label><input class="form-control" type="email" name="email"></div>
            <div><label>Perusahaan</label><input class="form-control" name="company"></div>
            <div><label>Sumber</label><select class="form-select" name="source">@foreach(['manual'=>'Manual','website'=>'Website','whatsapp'=>'WhatsApp','ads'=>'Iklan','referral'=>'Referral'] as $key=>$text)<option value="{{ $key }}">{{ $text }}</option>@endforeach</select></div>
            <div><label>Layanan diminati</label><input class="form-control" name="service_interest"></div>
            <div class="full"><label>Penanggung jawab</label><select class="form-select" name="assigned_to"><option value="">Belum ditentukan</option>@foreach($admins as $admin)<option value="{{ $admin->id }}">{{ $admin->name }}</option>@endforeach</select></div>
            <div class="full"><button class="btn btn-primary">Simpan kontak</button></div>
        </form>
        <hr>
        <h3>Impor kontak CSV</h3>
        <form method="post" action="{{ route('admin.whatsapp.contacts.import') }}" enctype="multipart/form-data" class="wa-form-grid">
            @csrf
            <div class="full"><label>File CSV</label><input class="form-control" type="file" name="csv_file" accept=".csv,text/csv" required><small class="wa-muted">Header wajib: phone. Opsional: name, email, company, source, service_interest, labels. Pisahkan beberapa label dengan tanda |.</small></div>
            <div class="full"><label>Sumber bawaan</label><select class="form-select" name="default_source"><option value="manual">Manual</option><option value="website">Website</option><option value="whatsapp">WhatsApp</option><option value="ads">Iklan</option><option value="referral">Referral</option></select></div>
            <div class="full"><button class="btn btn-outline-primary">Impor maksimal 5.000 baris</button></div>
        </form>
        <hr>
        <h3>Buat label</h3>
        <form method="post" action="{{ route('admin.whatsapp.labels.store') }}" class="wa-form-grid">
            @csrf
            <div class="full"><label>Nama label</label><input class="form-control" name="name" required placeholder="Contoh: Klien Prioritas"></div>
            <div><label>Kelompok</label><select class="form-select" name="category">@foreach(['source'=>'Sumber','status'=>'Status','service'=>'Layanan','document'=>'Dokumen','priority'=>'Prioritas','custom'=>'Kustom'] as $key=>$text)<option value="{{ $key }}">{{ $text }}</option>@endforeach</select></div>
            <div><label>Warna</label><input class="form-control" type="color" name="color" value="#0f766e"></div>
            <div class="full"><label>Deskripsi</label><textarea class="form-control" name="description" rows="2"></textarea></div>
            <div class="full"><button class="btn btn-outline-primary">Simpan label</button></div>
        </form>
    </aside>

    <section class="wa-card wa-span-12">
        <h2>Daftar label CRM</h2>
        <div class="wa-grid">@forelse($allLabels as $label)
            <article class="wa-card wa-span-4">
                <form method="post" action="{{ route('admin.whatsapp.labels.update',$label) }}" class="wa-form-grid">
                    @csrf @method('put')
                    <div class="full"><span class="wa-label" style="--label-color:{{ $label->color }}">{{ $label->name }}</span> <small>{{ $label->contacts_count }} kontak</small></div>
                    <div><label>Nama</label><input class="form-control" name="name" value="{{ $label->name }}" required></div>
                    <div><label>Warna</label><input class="form-control" type="color" name="color" value="{{ $label->color }}" required></div>
                    <div><label>Kelompok</label><select class="form-select" name="category">@foreach(['source'=>'Sumber','status'=>'Status','service'=>'Layanan','document'=>'Dokumen','priority'=>'Prioritas','custom'=>'Kustom'] as $key=>$text)<option value="{{ $key }}" @selected($label->category===$key)>{{ $text }}</option>@endforeach</select></div>
                    <div><label><input type="checkbox" name="is_active" value="1" @checked($label->is_active)> Aktif</label></div>
                    <div class="full"><label>Deskripsi</label><input class="form-control" name="description" value="{{ $label->description }}"></div>
                    <div class="full"><button class="btn btn-sm btn-outline-primary">Simpan label</button></div>
                </form>
                @if($label->contacts_count===0)<form method="post" action="{{ route('admin.whatsapp.labels.destroy',$label) }}" class="mt-2" onsubmit="return confirm('Hapus label ini?')">@csrf @method('delete')<button class="btn btn-sm btn-outline-danger">Hapus</button></form>@endif
            </article>
        @empty<p class="wa-muted">Belum ada label.</p>@endforelse</div>
    </section>
</div>
@endsection

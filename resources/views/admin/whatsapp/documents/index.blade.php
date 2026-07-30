@extends('layouts.admin')
@section('title', 'Document Vault')
@section('heading', 'Document Vault')
@section('content')
@include('admin.whatsapp._nav')
<div class="wa-grid">
    @foreach(['total'=>'Total dokumen','pending'=>'Menunggu arsip','stored'=>'Tersimpan privat','unverified'=>'Belum diverifikasi'] as $key=>$label)
        <section class="wa-card wa-span-3 wa-stat"><strong>{{ number_format($stats[$key] ?? 0) }}</strong><span>{{ $label }}</span></section>
    @endforeach

    <section class="wa-card wa-span-8">
        <h2>Arsip dokumen</h2>
        <form method="get" class="wa-form-grid mb-3">
            <div><label>Pencarian</label><input class="form-control" name="q" value="{{ $search }}" placeholder="Nama dokumen atau kontak"></div>
            <div><label>Status arsip</label><select class="form-select" name="archive_status"><option value="">Semua</option>@foreach(['pending'=>'Pending','stored'=>'Tersimpan','failed'=>'Gagal'] as $key=>$text)<option value="{{ $key }}" @selected($archiveStatus===$key)>{{ $text }}</option>@endforeach</select></div>
            <div class="full"><button class="btn btn-outline-primary">Terapkan filter</button> <a class="btn btn-outline-secondary" href="{{ route('admin.whatsapp.documents.index') }}">Reset</a></div>
        </form>
        <form id="bulkDocumentForm" method="post" action="{{ route('admin.whatsapp.documents.send-many') }}" class="wa-form-grid mb-3">
            @csrf
            <div class="full"><strong>Kirim beberapa dokumen final sekaligus</strong><br><small class="wa-muted">Centang maksimal 50 dokumen tersimpan. Caption hanya dikirim bersama dokumen pertama.</small></div>
            <div><label>Kontak tujuan</label><select class="form-select" name="contact_id" required><option value="">Pilih kontak</option>@foreach($contacts as $contact)<option value="{{ $contact->id }}">{{ $contact->name ?: $contact->phone }} · {{ $contact->phone }}</option>@endforeach</select></div>
            <div><label>Perangkat</label><select class="form-select" name="device_alias"><option value="support">Support</option><option value="transaction">Transaksi</option></select></div>
            <div class="full"><label>Caption opsional</label><input class="form-control" name="caption" placeholder="Dokumen final Anda telah selesai."></div>
            <div class="full"><button class="btn btn-success">Kirim dokumen yang dicentang</button></div>
        </form>
        @forelse($documents as $document)
            <article class="wa-document-card">
                <div class="wa-document-head"><div>@if($document->archive_status==='stored')<label><input type="checkbox" form="bulkDocumentForm" name="document_ids[]" value="{{ $document->id }}"> <strong>{{ $document->name }}</strong></label>@else<strong>{{ $document->name }}</strong>@endif<br><small>{{ $document->original_name ?: $document->source_url }}</small></div><div><span class="wa-status {{ $document->archive_status==='stored'?'ok':($document->archive_status==='failed'?'bad':'warn') }}">{{ $document->archive_status }}</span> <span class="wa-status">{{ $document->verification_status }}</span></div></div>
                <div class="wa-muted">Kontak: {{ $document->contact?->name ?: '-' }} · Lead: {{ $document->lead?->title ?: '-' }} · Order: {{ $document->serviceOrder?->order_number ?: '-' }} · Sumber: {{ $document->source }}</div>
                <div class="wa-inline-actions mt-3">
                    @if($document->path)<a class="btn btn-sm btn-outline-primary" href="{{ route('admin.whatsapp.documents.download',$document) }}">Unduh</a>@endif
                    @if($document->archive_status!=='stored' && $document->source_url)<form method="post" action="{{ route('admin.whatsapp.documents.archive',$document) }}">@csrf<button class="btn btn-sm btn-outline-primary">Arsipkan sekarang</button></form>@endif
                </div>
                <div class="wa-grid mt-3">
                    <form class="wa-form-grid wa-span-6" method="post" action="{{ route('admin.whatsapp.documents.update',$document) }}">
                        @csrf @method('put')
                        <div><label>Kategori</label><select class="form-select" name="category">@foreach(['requirement'=>'Persyaratan','revision'=>'Revisi','payment'=>'Pembayaran','process'=>'Proses','final'=>'Final','whatsapp_attachment'=>'Lampiran WA','other'=>'Lainnya'] as $key=>$text)<option value="{{ $key }}" @selected($document->category===$key)>{{ $text }}</option>@endforeach</select></div>
                        <div><label>Verifikasi</label><select class="form-select" name="verification_status">@foreach(['unverified'=>'Belum dicek','valid'=>'Valid','needs_revision'=>'Perlu revisi','rejected'=>'Ditolak'] as $key=>$text)<option value="{{ $key }}" @selected($document->verification_status===$key)>{{ $text }}</option>@endforeach</select></div>
                        <div class="full"><label>Catatan</label><input class="form-control" name="notes" value="{{ $document->notes }}"></div>
                        <div class="full"><button class="btn btn-sm btn-outline-secondary">Simpan metadata</button></div>
                    </form>
                    @if($document->archive_status==='stored')
                    <form class="wa-form-grid wa-span-6" method="post" action="{{ route('admin.whatsapp.documents.send',$document) }}">
                        @csrf
                        <div class="full"><label>Kirim ke kontak</label><select class="form-select" name="contact_id" required><option value="">Pilih kontak</option>@foreach($contacts as $contact)<option value="{{ $contact->id }}" @selected($document->contact_id===$contact->id)>{{ $contact->name ?: $contact->phone }} · {{ $contact->phone }}</option>@endforeach</select></div>
                        <div><label>Perangkat</label><select class="form-select" name="device_alias"><option value="support">Support</option><option value="transaction">Transaksi</option></select></div>
                        <div><label>Caption</label><input class="form-control" name="caption" placeholder="Dokumen Anda telah selesai."></div>
                        <div class="full"><button class="btn btn-sm btn-primary">Kirim via WhatsApp</button></div>
                    </form>
                    @endif
                </div>
            </article>
        @empty<p class="wa-muted">Belum ada dokumen.</p>@endforelse
        {{ $documents->links() }}
    </section>

    <aside class="wa-card wa-span-4">
        <h2>Unggah dokumen privat</h2>
        <p class="wa-muted">File disimpan di <code>storage/app/private</code> dan tidak dapat dibuka melalui URL publik biasa.</p>
        <form method="post" action="{{ route('admin.whatsapp.documents.store') }}" enctype="multipart/form-data" class="wa-form-grid">
            @csrf
            <div class="full"><label>File</label><input class="form-control" type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx,.zip"></div>
            <div class="full"><label>Nama dokumen</label><input class="form-control" name="name" required></div>
            <div><label>Kontak</label><select class="form-select" name="contact_id"><option value="">Tidak dipilih</option>@foreach($contacts as $contact)<option value="{{ $contact->id }}">{{ $contact->name ?: $contact->phone }}</option>@endforeach</select></div>
            <div><label>Lead</label><select class="form-select" name="lead_id"><option value="">Tidak dipilih</option>@foreach($leads as $lead)<option value="{{ $lead->id }}">{{ $lead->title }}</option>@endforeach</select></div>
            <div class="full"><label>Kategori</label><select class="form-select" name="category"><option value="requirement">Persyaratan</option><option value="revision">Revisi</option><option value="payment">Pembayaran</option><option value="process">Dokumen proses</option><option value="final">Dokumen final</option><option value="other">Lainnya</option></select></div>
            <div class="full"><label>Catatan</label><textarea class="form-control" name="notes" rows="3"></textarea></div>
            <div class="full"><button class="btn btn-primary">Simpan ke vault</button></div>
        </form>
    </aside>
</div>
@endsection

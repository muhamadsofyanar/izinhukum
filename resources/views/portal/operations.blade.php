@extends('layouts.admin')
@php
$labels=['announcements'=>'Pengumuman','materials'=>'Bank Konten','tickets'=>'Tiket Bantuan','commissions'=>'Komisi Mitra','audit'=>'Audit Log'];
@endphp
@section('title',$labels[$module])
@section('heading',$labels[$module])
@section('content')
@if($module==='announcements' && $isAdmin)
<form class="admin-panel mb-4 stack-form" method="post" action="{{ route('admin.operations.store',$module) }}">@csrf
<h2>Terbitkan pengumuman</h2><input class="form-control" name="title" placeholder="Judul" required><textarea class="form-control" name="body" rows="4" placeholder="Isi pengumuman" required></textarea><label><input type="checkbox" name="is_pinned" value="1"> Sematkan</label><button class="btn btn-primary">Terbitkan</button></form>
@elseif($module==='materials' && $isAdmin)
<form class="admin-panel mb-4 stack-form" method="post" action="{{ route('admin.operations.store',$module) }}">@csrf
<h2>Tambah konten untuk mitra</h2><input class="form-control" name="title" placeholder="Judul konten" required><input class="form-control" name="category" placeholder="Kategori layanan / format" required><textarea class="form-control" name="description" placeholder="Deskripsi dan petunjuk penggunaan"></textarea><input class="form-control" type="url" name="file_url" placeholder="URL file Google Drive/Canva/penyimpanan" required><button class="btn btn-primary">Simpan ke bank konten</button></form>
@elseif($module==='tickets' && !$isAdmin)
<form class="admin-panel mb-4 stack-form" method="post" action="{{ route('partner.operations.store',$module) }}">@csrf
<h2>Buat tiket baru</h2><input class="form-control" name="subject" placeholder="Subjek" required><select class="form-select" name="category"><option value="general">Umum</option><option value="service">Layanan</option><option value="invoice">Invoice</option><option value="technical">Teknis</option></select><select class="form-select" name="priority"><option value="normal">Normal</option><option value="high">Tinggi</option><option value="urgent">Mendesak</option><option value="low">Rendah</option></select><textarea class="form-control" name="message" rows="4" required></textarea><button class="btn btn-primary">Kirim tiket</button></form>
@elseif($module==='commissions' && $isAdmin)
<form class="admin-panel mb-4 inline-admin-form" method="post" action="{{ route('admin.operations.store',$module) }}">@csrf
<select class="form-select" name="partner_id" required><option value="">Pilih mitra</option>@foreach(\App\Models\User::where('role','partner')->orderBy('name')->get() as $partner)<option value="{{ $partner->id }}">{{ $partner->name }}</option>@endforeach</select><input class="form-control" type="number" name="amount" min="0" placeholder="Nilai komisi" required><input class="form-control" name="notes" placeholder="Catatan"><button class="btn btn-primary">Catat komisi</button></form>
@endif

<section class="admin-panel">
@if($module==='announcements')
<div class="announcement-list">@forelse($data as $item)<article><small>{{ $item->published_at?->format('d/m/Y H:i') }} @if($item->is_pinned) · Disematkan @endif</small><h2>{{ $item->title }}</h2><p>{{ $item->body }}</p></article>@empty<p>Belum ada pengumuman.</p>@endforelse</div>
@elseif($module==='materials')
<div class="course-grid">@forelse($data as $item)<article class="course-card"><small>{{ $item->category }}</small><h2>{{ $item->title }}</h2><p>{{ $item->description }}</p><a class="btn btn-outline-primary" href="{{ $item->file_url }}" target="_blank" rel="noopener">Unduh / buka konten</a></article>@empty<p>Belum ada konten untuk mitra.</p>@endforelse</div>
@elseif($module==='tickets')
<div class="table-responsive"><table class="table admin-table"><thead><tr><th>Referensi</th><th>Mitra/Subjek</th><th>Prioritas</th><th>Status/Tanggapan</th></tr></thead><tbody>@forelse($data as $item)<tr><td>{{ $item->reference }}</td><td>@if($isAdmin)<small>{{ $item->user->name }}</small>@endif<strong>{{ $item->subject }}</strong><small>{{ $item->message }}</small></td><td>{{ ucfirst($item->priority) }}</td><td>@if($isAdmin)<form method="post" action="{{ route('admin.tickets.update',$item) }}" class="stack-form">@csrf @method('put')<select class="form-select form-select-sm" name="status">@foreach(['open','in_progress','resolved','closed'] as $status)<option @selected($item->status===$status)>{{ $status }}</option>@endforeach</select><textarea class="form-control" name="admin_response" placeholder="Tanggapan">{{ $item->admin_response }}</textarea><button class="btn btn-sm btn-secondary">Simpan</button></form>@else<strong>{{ ucfirst($item->status) }}</strong><small>{{ $item->admin_response ?: 'Menunggu tanggapan admin.' }}</small>@endif</td></tr>@empty<tr><td colspan="4">Belum ada tiket.</td></tr>@endforelse</tbody></table></div>
@elseif($module==='commissions')
<div class="table-responsive">
    <table class="table admin-table">
        <thead><tr><th>Mitra</th><th>Sumber transaksi</th><th>Tarif</th><th>Nilai</th><th>Catatan</th><th>Status</th></tr></thead>
        <tbody>
        @forelse($data as $item)
            @php($partnerCanOpenInvoice = $item->invoice && ($item->invoice->created_by === $currentUser->id || $item->invoice->partner_id === $currentUser->id))
            <tr>
                <td><strong>{{ $isAdmin ? $item->partner->name : $currentUser->name }}</strong><small>{{ $item->partner->partner_code }}</small></td>
                <td>
                    @if($item->invoice && $isAdmin)
                        <a href="{{ route('admin.invoices.show', $item->invoice) }}">{{ $item->invoice->invoice_number }}</a>
                    @elseif($item->invoice && $partnerCanOpenInvoice)
                        <a href="{{ route('partner.invoices.show', $item->invoice) }}">{{ $item->invoice->invoice_number }}</a>
                    @elseif($item->invoice)
                        <span>{{ $item->invoice->invoice_number }}</span>
                    @else
                        <span>Komisi manual</span>
                    @endif
                    <small>{{ $item->payment?->receipt_number ?: ucfirst($item->source) }}</small>
                </td>
                <td>{{ $item->rate_bps > 0 ? number_format($item->rate_bps / 100, 0, ',', '.').'%' : 'Manual' }}</td>
                <td><strong>Rp{{ number_format($item->amount,0,',','.') }}</strong></td>
                <td>{{ $item->notes ?: '—' }}</td>
                <td>
                    @if($isAdmin)
                        <form method="post" action="{{ route('admin.commissions.update',$item) }}" class="d-flex gap-2">
                            @csrf
                            @method('put')
                            <select class="form-select form-select-sm" name="status">
                                @foreach(['pending'=>'Menunggu','approved'=>'Disetujui','paid'=>'Dibayar','cancelled'=>'Dibatalkan','adjustment_required'=>'Perlu penyesuaian'] as $status=>$label)
                                    <option value="{{ $status }}" @selected($item->status===$status)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <button class="btn btn-sm btn-secondary">Simpan</button>
                        </form>
                    @else
                        {{ ['pending'=>'Menunggu','approved'=>'Disetujui','paid'=>'Dibayar','cancelled'=>'Dibatalkan','adjustment_required'=>'Perlu penyesuaian'][$item->status] ?? ucfirst($item->status) }}
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6">Belum ada komisi.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@else
<div class="table-responsive"><table class="table admin-table"><thead><tr><th>Waktu</th><th>Pengguna</th><th>Aktivitas</th><th>Objek</th></tr></thead><tbody>@forelse($data as $item)<tr><td>{{ $item->created_at->format('d/m/Y H:i') }}</td><td>{{ $item->user_id ?: 'Sistem' }}</td><td>{{ $item->action }}</td><td>{{ class_basename($item->subject_type ?: '—') }} #{{ $item->subject_id }}</td></tr>@empty<tr><td colspan="4">Belum ada aktivitas.</td></tr>@endforelse</tbody></table></div>
@endif
{{ $data->links() }}
</section>
@endsection

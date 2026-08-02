@extends('layouts.admin')

@section('title', 'Penawaran Digital')
@section('heading', 'Penawaran Digital')
@section('header_action')<a class="btn btn-primary" href="{{ route('admin.quotes.create') }}">+ Buat penawaran</a>@endsection

@section('content')
<div class="admin-note mb-3">Kirim tautan melalui WhatsApp. Saat klien menyetujui, sistem membuat invoice dan memperbarui pipeline secara otomatis.</div>
<section class="admin-panel mb-3"><form class="p-3 row g-2 align-items-end" method="get"><div class="col-lg-7"><label class="form-label">Cari penawaran</label><input class="form-control" name="q" value="{{ $search }}" placeholder="Nomor, nama, perusahaan, atau WhatsApp"></div><div class="col-lg-3"><label class="form-label">Status</label><select class="form-select" name="status"><option value="">Semua status</option>@foreach(\App\Models\SalesQuote::STATUSES as $key=>$label)<option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>@endforeach</select></div><div class="col-lg-2 d-flex gap-2"><button class="btn btn-primary flex-grow-1">Terapkan</button><a class="btn btn-outline-secondary" href="{{ route('admin.quotes.index') }}">Reset</a></div></form></section>
<section class="admin-panel"><div class="table-responsive"><table class="table admin-table"><thead><tr><th>Penawaran</th><th>Penerima</th><th>Berlaku</th><th>Nilai</th><th>Status</th><th></th></tr></thead><tbody>
@forelse($quotes as $quote)<tr><td><strong>{{ $quote->quote_number }}</strong><small>{{ $quote->created_at->format('d/m/Y H:i') }} · {{ $quote->creator?->name }}</small></td><td>{{ $quote->recipient_name }}<small>{{ $quote->recipient_company ?: $quote->recipient_phone }}</small></td><td>{{ $quote->valid_until->format('d/m/Y') }}</td><td><strong>{{ $quote->formattedTotal() }}</strong>@if($quote->discount > 0)<small>Potongan Rp{{ number_format($quote->discount, 0, ',', '.') }}</small>@endif</td><td><span class="status status-{{ $quote->status === 'approved' ? 'paid' : ($quote->status === 'cancelled' || $quote->status === 'rejected' ? 'cancelled' : $quote->status) }}">{{ $quote->statusLabel() }}</span></td><td><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.quotes.show', $quote) }}">Detail</a></td></tr>
@empty<tr><td colspan="6"><div class="empty-state"><h2>Belum ada penawaran</h2><p>Buat penawaran untuk lead dari pipeline atau langsung dari halaman ini.</p></div></td></tr>@endforelse
</tbody></table></div></section>
@if($quotes->hasPages())<div class="mt-3">{{ $quotes->links() }}</div>@endif
@endsection

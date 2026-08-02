@extends('layouts.admin')

@section('title', 'Playbook Penjualan')
@section('heading', 'Playbook Penjualan Manual')

@section('content')
<div class="admin-note mb-3"><strong>Tidak ada pengiriman otomatis.</strong> Template hanya mengisi pesan dan membuka WhatsApp. Admin tetap memeriksa serta menekan tombol kirim sendiri.</div>
<details class="admin-panel mb-3" @if($errors->any()) open @endif><summary class="admin-panel-head"><h2>Tambah template pesan</h2><span>Placeholder tersedia di bawah</span></summary><form class="p-4 form-grid" method="post" action="{{ route('admin.sales-messages.store') }}">@csrf
    <label class="field"><span>Nama *</span><input class="form-control" name="name" value="{{ old('name') }}" required></label>
    <label class="field"><span>Tujuan</span><select class="form-select" name="purpose">@foreach($purposes as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
    <label class="field"><span>Tahap pipeline</span><select class="form-select" name="stage"><option value="">Semua tahap</option>@foreach($stages as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
    <label class="field"><span>Urutan</span><input class="form-control" type="number" min="0" name="sort_order" value="{{ old('sort_order', 100) }}"></label>
    <label class="field field-wide"><span>Isi pesan *</span><textarea class="form-control" name="body" rows="5" required>{{ old('body') }}</textarea><small class="text-muted">Placeholder: @{{name}}, @{{service}}, @{{reference}}, @{{quote_number}}, @{{quote_url}}, @{{invoice_url}}, dan @{{admin_name}}.</small></label>
    <input type="hidden" name="is_active" value="1"><div><button class="btn btn-primary">Simpan template</button></div>
</form></details>

<div class="playbook-grid">
@foreach($templates as $template)<article class="admin-panel"><form class="p-3 stack-form" method="post" action="{{ route('admin.sales-messages.update', $template) }}">@csrf @method('PUT')
    <div class="d-flex justify-content-between gap-2"><span class="status status-{{ $template->is_active ? 'paid' : 'cancelled' }}">{{ $template->is_active ? 'Aktif' : 'Nonaktif' }}</span><small>{{ $template->purposeLabel() }}</small></div>
    <label class="field"><span>Nama</span><input class="form-control" name="name" value="{{ $template->name }}" required></label>
    <div class="row g-2"><label class="field col-md-6"><span>Tujuan</span><select class="form-select" name="purpose">@foreach($purposes as $key=>$label)<option value="{{ $key }}" @selected($template->purpose === $key)>{{ $label }}</option>@endforeach</select></label><label class="field col-md-6"><span>Tahap</span><select class="form-select" name="stage"><option value="">Semua tahap</option>@foreach($stages as $key=>$label)<option value="{{ $key }}" @selected($template->stage === $key)>{{ $label }}</option>@endforeach</select></label></div>
    <label class="field"><span>Pesan</span><textarea class="form-control" name="body" rows="6" required>{{ $template->body }}</textarea></label>
    <div class="row g-2"><label class="field col-6"><span>Urutan</span><input class="form-control" type="number" min="0" name="sort_order" value="{{ $template->sort_order }}"></label><label class="check-field col-6"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($template->is_active)> Aktif</label></div>
    <button class="btn btn-primary">Simpan perubahan</button>
</form><form class="px-3 pb-3" method="post" action="{{ route('admin.sales-messages.destroy', $template) }}" onsubmit="return confirm('Hapus template pesan ini?');">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Hapus</button></form></article>@endforeach
</div>
@endsection

@extends('layouts.admin')

@php
    $isEdit = (bool) $quote;
    $itemsForForm = old('items', $formItems);
    $name = $quote?->recipient_name ?? $inquiry?->name ?? $lead?->contact?->name ?? '';
    $company = $quote?->recipient_company ?? $inquiry?->company_name ?? $lead?->contact?->company ?? '';
    $email = $quote?->recipient_email ?? $inquiry?->email ?? $lead?->contact?->email ?? '';
    $phone = $quote?->recipient_phone ?? $inquiry?->phone ?? $lead?->contact?->phone ?? '';
    $address = $quote?->recipient_address ?? '';
    $selectedTemplateId = old('sales_quote_template_id', $quote?->sales_quote_template_id ?? $defaultTemplate?->id);
    $templateValidity = $defaultTemplate?->validity_days ?? 14;
    $templateDueDays = $defaultTemplate?->invoice_due_days ?? 7;
@endphp

@section('title', $isEdit ? 'Ubah Penawaran' : 'Buat Penawaran')
@section('heading', $isEdit ? 'Ubah Penawaran Draf' : 'Buat Penawaran')

@section('content')
<form class="portal-form" id="quote-form" method="post" action="{{ $isEdit ? route('admin.quotes.update', $quote) : route('admin.quotes.store') }}">
    @csrf @if($isEdit) @method('PUT') @endif
    @if($inquiry)<input type="hidden" name="inquiry_id" value="{{ $inquiry->id }}">@endif
    @if($lead)<input type="hidden" name="crm_lead_id" value="{{ $lead->id }}">@endif
    @if($inquiry)<div class="admin-note mb-3">Data diambil dari proposal <strong>{{ $inquiry->reference }}</strong>. Pipeline akan diperbarui saat penawaran diterbitkan atau disetujui.</div>@endif

    @if($quoteTemplatesEnabled)<section class="admin-panel portal-section mb-3">
        <div class="admin-panel-head"><h2>Template penawaran</h2><a href="{{ route('admin.quote-templates.index') }}">Kelola template</a></div>
        <div class="p-4"><label class="field"><span>Pilih template ruang lingkup dan ketentuan</span><select class="form-select" id="sales_quote_template_id" name="sales_quote_template_id"><option value="">Tanpa template</option>@foreach($quoteTemplates as $template)<option value="{{ $template->id }}" @selected($selectedTemplateId == $template->id)>{{ $template->name }}{{ $template->service ? ' · '.$template->service->name : ' · Semua layanan' }}</option>@endforeach</select><small class="text-muted">Mengganti pilihan akan mengisi ruang lingkup, ketentuan, masa berlaku, dan jatuh tempo. Isinya tetap dapat disunting sebelum disimpan.</small></label></div>
    </section>@endif

    <section class="admin-panel portal-section">
        <div class="admin-panel-head"><h2>Penerima & masa berlaku</h2></div>
        <div class="p-4 row g-3">
            <div class="col-md-6"><label class="form-label">Nama penerima *</label><input class="form-control" name="recipient_name" value="{{ old('recipient_name', $name) }}" required></div>
            <div class="col-md-6"><label class="form-label">Perusahaan</label><input class="form-control" name="recipient_company" value="{{ old('recipient_company', $company) }}"></div>
            <div class="col-md-6"><label class="form-label">Email</label><input class="form-control" type="email" name="recipient_email" value="{{ old('recipient_email', $email) }}"></div>
            <div class="col-md-6"><label class="form-label">WhatsApp</label><input class="form-control" name="recipient_phone" value="{{ old('recipient_phone', $phone) }}"></div>
            <div class="col-12"><label class="form-label">Alamat</label><textarea class="form-control" name="recipient_address" rows="2">{{ old('recipient_address', $address) }}</textarea></div>
            <div class="col-md-4"><label class="form-label">Tanggal penawaran</label><input class="form-control" type="date" name="issue_date" value="{{ old('issue_date', $quote?->issue_date?->format('Y-m-d') ?? now()->format('Y-m-d')) }}" required></div>
            <div class="col-md-4"><label class="form-label">Berlaku sampai</label><input class="form-control" id="valid_until" type="date" name="valid_until" value="{{ old('valid_until', $quote?->valid_until?->format('Y-m-d') ?? now()->addDays($templateValidity)->format('Y-m-d')) }}" required></div>
            <div class="col-md-4"><label class="form-label">Jatuh tempo invoice (hari)</label><input class="form-control" id="invoice_due_days" type="number" min="1" max="90" name="invoice_due_days" value="{{ old('invoice_due_days', $quote?->invoice_due_days ?? $templateDueDays) }}" required></div>
        </div>
    </section>

    <section class="admin-panel portal-section mt-3">
        <div class="admin-panel-head"><h2>Item layanan</h2><button class="btn btn-sm btn-outline-primary" id="add-quote-item" type="button">+ Tambah item</button></div>
        <div class="table-responsive"><table class="table admin-table invoice-item-table"><thead><tr><th>Paket</th><th>Deskripsi</th><th>Jumlah</th><th>Harga satuan</th><th></th></tr></thead><tbody id="quote-items">
        @foreach($itemsForForm as $index=>$formItem)
            <tr class="quote-item">
                <td><select class="form-select package-select" name="items[{{ $index }}][service_package_id]"><option value="">Item manual</option>@foreach($packages->groupBy(fn($package) => $package->service?->short_name ?: $package->service?->name) as $serviceName=>$items)<optgroup label="{{ $serviceName }}">@foreach($items as $package)<option value="{{ $package->id }}" @selected(($formItem['service_package_id'] ?? '') == $package->id)>{{ $package->name }}</option>@endforeach</optgroup>@endforeach</select></td>
                <td><input class="form-control item-description" name="items[{{ $index }}][description]" value="{{ $formItem['description'] ?? '' }}" required></td>
                <td><input class="form-control item-quantity" type="number" min="1" max="100" name="items[{{ $index }}][quantity]" value="{{ $formItem['quantity'] ?? 1 }}" required></td>
                <td><input class="form-control item-price" type="number" min="0" name="items[{{ $index }}][unit_price]" value="{{ $formItem['unit_price'] ?? 0 }}" required><small class="price-help"></small></td>
                <td><button class="btn btn-sm btn-outline-danger remove-item" type="button">×</button></td>
            </tr>
        @endforeach
        </tbody></table></div>
        <div class="p-4 pt-0 row"><div class="col-md-5 ms-auto"><label class="form-label">Potongan nominal</label><input class="form-control" type="number" min="0" name="discount" value="{{ old('discount', $quote?->discount ?? $inquiry?->coupon_discount_amount ?? 0) }}"><small class="text-muted">Kupon proposal dimuat otomatis dan tetap tercatat pada dokumen.</small></div></div>
    </section>

    <section class="admin-panel portal-section mt-3"><div class="admin-panel-head"><h2>Ruang lingkup & ketentuan</h2></div><div class="p-4 row g-3">
        <div class="col-lg-6"><label class="form-label">Ruang lingkup pekerjaan</label><textarea class="form-control" id="quote_scope" name="scope" rows="7" placeholder="Contoh: konsultasi awal, pemeriksaan dokumen, pengajuan, dan pendampingan sampai terbit.">{{ old('scope', $quote?->scope ?? $defaultTemplate?->scope) }}</textarea></div>
        <div class="col-lg-6"><label class="form-label">Ketentuan</label><textarea class="form-control" id="quote_terms" name="terms" rows="7" placeholder="Contoh: pekerjaan dimulai setelah pembayaran dan dokumen lengkap. Biaya di luar ruang lingkup dikonfirmasi dahulu.">{{ old('terms', $quote?->terms ?? $defaultTemplate?->terms ?? "Pekerjaan dimulai setelah pembayaran dan dokumen persyaratan dinyatakan lengkap.\nBiaya tambahan di luar ruang lingkup akan dikonfirmasi dan disetujui terlebih dahulu.") }}</textarea></div>
        <div class="col-12"><label class="form-label">Catatan internal/umum</label><textarea class="form-control" id="quote_notes" name="notes" rows="2">{{ old('notes', $quote?->notes ?? $defaultTemplate?->notes) }}</textarea></div>
    </div></section>

    <div class="d-flex gap-2 mt-3"><button class="btn btn-primary">{{ $isEdit ? 'Simpan perubahan' : 'Simpan draf penawaran' }}</button><a class="btn btn-outline-secondary" href="{{ $isEdit ? route('admin.quotes.show', $quote) : route('admin.quotes.index') }}">Batal</a></div>
</form>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const data = @json($packageOptions);
    const map = Object.fromEntries(data.map(item => [String(item.id), item]));
    const templates = Object.fromEntries(@json($templateOptions).map(item => [String(item.id), item]));
    const body = document.getElementById('quote-items');
    let next = {{ count($itemsForForm) }};
    const rupiah = value => 'Rp' + Number(value || 0).toLocaleString('id-ID');
    function sync(row, overwrite = true) {
        const item = map[row.querySelector('.package-select').value];
        if (!item) { row.querySelector('.price-help').textContent = ''; return; }
        row.querySelector('.item-description').value ||= item.description;
        if (overwrite || !row.querySelector('.item-price').value) row.querySelector('.item-price').value = item.price;
        row.querySelector('.price-help').textContent = 'Minimum end user ' + rupiah(item.minimum);
    }
    function bind(row) {
        row.querySelector('.package-select').addEventListener('change', () => sync(row, true));
        row.querySelector('.remove-item').addEventListener('click', () => { if (body.querySelectorAll('tr').length > 1) row.remove(); });
        sync(row, false);
    }
    document.getElementById('add-quote-item').addEventListener('click', () => {
        if (body.querySelectorAll('tr').length >= 15) return;
        const row = body.querySelector('tr').cloneNode(true);
        row.querySelectorAll('input, select').forEach(field => {
            field.name = field.name.replace(/items\[\d+]/, `items[${next}]`);
            if (field.classList.contains('item-quantity')) field.value = '1'; else field.value = '';
        });
        row.querySelector('.price-help').textContent = '';
        next++; body.appendChild(row); bind(row);
    });
    document.getElementById('sales_quote_template_id')?.addEventListener('change', event => {
        const template = templates[event.target.value];
        if (!template) return;
        document.getElementById('quote_scope').value = template.scope || '';
        document.getElementById('quote_terms').value = template.terms || '';
        document.getElementById('quote_notes').value = template.notes || '';
        document.getElementById('invoice_due_days').value = template.invoice_due_days || 7;
        const valid = new Date(); valid.setDate(valid.getDate() + Number(template.validity_days || 14));
        document.getElementById('valid_until').value = valid.toISOString().slice(0, 10);
    });
    body.querySelectorAll('tr').forEach(bind);
});
</script>
@endpush

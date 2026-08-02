@extends('layouts.admin')

@php
    $prefix = $user->isAdmin() ? 'admin' : 'partner';
    $isEdit = isset($invoice) && $invoice;
    $itemsForForm = old('items', $formItems);
@endphp

@section('title', $isEdit ? 'Ubah Invoice' : 'Buat Invoice')
@section('heading', $isEdit ? 'Ubah Invoice Draf' : 'Buat Invoice')

@section('content')
<form class="portal-form"
      action="{{ $isEdit ? route($prefix.'.invoices.update', $invoice) : route($prefix.'.invoices.store') }}"
      method="post"
      id="invoice-form">
    @csrf
    @if($isEdit) @method('PUT') @endif
    @if($selectedInquiryId)
        <input type="hidden" name="inquiry_id" value="{{ $selectedInquiryId }}">
    @endif

    <section class="admin-panel portal-section">
        <div class="admin-panel-head">
            <h2>Data penerima</h2>
            @if($isEdit)<span class="status status-draft">{{ $invoice->invoice_number }}</span>@endif
        </div>
        <div class="p-4">
            @if($sourceInquiry)
                <div class="admin-note mb-3">
                    Proposal <strong>{{ $sourceInquiry->reference }}</strong> dimuat ke invoice.
                    @if($sourceInquiry->referredByPartner)
                        Sumber mitra: <strong>{{ $sourceInquiry->referredByPartner->name }} · {{ $sourceInquiry->referral_code }}</strong>.
                    @endif
                    @if($sourceInquiry->coupon_code)
                        Promo: <strong>{{ $sourceInquiry->coupon_code }}</strong> dengan potongan tercatat
                        <strong>Rp{{ number_format($sourceInquiry->coupon_discount_amount, 0, ',', '.') }}</strong>.
                    @endif
                </div>
            @endif
            @if($user->isAdmin())
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label" for="recipient_type">Jenis penerima</label>
                        <select class="form-select" id="recipient_type" name="recipient_type">
                            @php($selectedRecipient = old('recipient_type', $invoice?->recipient_type ?? 'end_user'))
                            <option value="end_user" @selected($selectedRecipient === 'end_user')>End user</option>
                            <option value="partner" @selected($selectedRecipient === 'partner')>Mitra LegaOne</option>
                        </select>
                    </div>
                    <div class="col-md-6 partner-picker d-none">
                        <label class="form-label" for="partner_id">Pilih mitra</label>
                        <select class="form-select" id="partner_id" name="partner_id">
                            <option value="">Pilih mitra</option>
                            @foreach($partners as $partner)
                                <option value="{{ $partner->id }}" @selected(old('partner_id', $invoice?->partner_id) == $partner->id)>
                                    {{ $partner->partner_code }} · {{ $partner->name }}{{ $partner->company_name ? ' · '.$partner->company_name : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="referred_by_partner_id">Sumber pemasaran mitra</label>
                        <select class="form-select" id="referred_by_partner_id" name="referred_by_partner_id">
                            <option value="">Tanpa referral mitra</option>
                            @foreach($referredPartners as $referralPartner)
                                <option value="{{ $referralPartner->id }}" @selected(old('referred_by_partner_id', $selectedReferralPartnerId) == $referralPartner->id)>
                                    {{ $referralPartner->partner_code }} · {{ $referralPartner->name }}
                                </option>
                            @endforeach
                        </select>
                        <small class="text-muted">Dipakai untuk atribusi penjualan dan komisi otomatis.</small>
                    </div>
                </div>
            @endif

            <div class="end-user-fields row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="recipient_name">Nama penerima *</label>
                    <input class="form-control" id="recipient_name" name="recipient_name" value="{{ old('recipient_name', $recipientDefaults['name']) }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="recipient_company">Perusahaan/organisasi</label>
                    <input class="form-control" id="recipient_company" name="recipient_company" value="{{ old('recipient_company', $recipientDefaults['company']) }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="recipient_email">Email</label>
                    <input class="form-control" id="recipient_email" name="recipient_email" type="email" value="{{ old('recipient_email', $recipientDefaults['email']) }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="recipient_phone">WhatsApp</label>
                    <input class="form-control" id="recipient_phone" name="recipient_phone" value="{{ old('recipient_phone', $recipientDefaults['phone']) }}">
                </div>
                <div class="col-12">
                    <label class="form-label" for="recipient_address">Alamat</label>
                    <textarea class="form-control" id="recipient_address" name="recipient_address" rows="2">{{ old('recipient_address', $recipientDefaults['address']) }}</textarea>
                </div>
            </div>
        </div>
    </section>

    <section class="admin-panel portal-section mt-3">
        <div class="admin-panel-head">
            <h2>Item layanan</h2>
            <button class="btn btn-sm btn-outline-primary" id="add-invoice-item" type="button">+ Tambah item</button>
        </div>
        <div class="table-responsive">
            <table class="table admin-table invoice-item-table">
                <thead><tr><th>Paket layanan</th><th>Deskripsi</th><th>Jumlah</th><th>Harga satuan</th><th></th></tr></thead>
                <tbody id="invoice-items">
                    @foreach($itemsForForm as $index => $formItem)
                    <tr class="invoice-item">
                        <td>
                            <select class="form-select package-select" name="items[{{ $index }}][service_package_id]" required>
                                <option value="">Pilih paket</option>
                                @foreach($packages->groupBy(fn($package) => $package->service->short_name) as $serviceName => $items)
                                    <optgroup label="{{ $serviceName }}">
                                        @foreach($items as $package)
                                            <option value="{{ $package->id }}" @selected(($formItem['service_package_id'] ?? '') == $package->id)>{{ $package->name }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </td>
                        <td><input class="form-control item-description" name="items[{{ $index }}][description]" value="{{ $formItem['description'] ?? '' }}" placeholder="Otomatis dari paket"></td>
                        <td><input class="form-control item-quantity" name="items[{{ $index }}][quantity]" type="number" min="1" max="100" value="{{ $formItem['quantity'] ?? 1 }}" required></td>
                        <td>
                            <input class="form-control item-price" name="items[{{ $index }}][unit_price]" type="number" min="0" value="{{ $formItem['unit_price'] ?? '' }}" required>
                            <small class="price-help"></small>
                        </td>
                        <td><button class="btn btn-sm btn-outline-danger remove-item" type="button" aria-label="Hapus item">×</button></td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="admin-panel portal-section mt-3">
        <div class="admin-panel-head"><h2>Tanggal dan catatan</h2></div>
        <div class="p-4">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="issue_date">Tanggal invoice</label>
                    <input class="form-control" id="issue_date" name="issue_date" type="date"
                           value="{{ old('issue_date', $invoice?->issue_date?->format('Y-m-d') ?? now()->format('Y-m-d')) }}" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="due_date">Jatuh tempo</label>
                    <input class="form-control" id="due_date" name="due_date" type="date"
                           value="{{ old('due_date', $invoice?->due_date?->format('Y-m-d') ?? now()->addDays(7)->format('Y-m-d')) }}">
                </div>
                <div class="col-12">
                    <label class="form-label" for="notes">Catatan</label>
                    <textarea class="form-control" id="notes" name="notes" rows="3" placeholder="Contoh: pekerjaan dimulai setelah pembayaran dan dokumen lengkap.">{{ old('notes', $invoice?->notes) }}</textarea>
                </div>
            </div>
        </div>
    </section>

    <div class="d-flex gap-2 mt-3">
        <button class="btn btn-primary" type="submit">{{ $isEdit ? 'Simpan perubahan' : 'Simpan invoice' }}</button>
        <a class="btn btn-outline-secondary" href="{{ $isEdit ? route($prefix.'.invoices.show', $invoice) : route($prefix.'.invoices.index') }}">Batal</a>
    </div>
</form>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const packages = @json($packageOptions);
    const packageMap = Object.fromEntries(packages.map(item => [String(item.id), item]));
    const tbody = document.getElementById('invoice-items');
    const recipientType = document.getElementById('recipient_type');
    let nextIndex = {{ count($itemsForForm) }};

    const rupiah = value => 'Rp' + Number(value || 0).toLocaleString('id-ID');
    const isPartnerRecipient = () => recipientType?.value === 'partner';
    const isAdmin = @json($user->isAdmin());

    function syncRow(row, overwritePrice = true) {
        const data = packageMap[row.querySelector('.package-select').value];
        if (!data) return;
        const price = isAdmin && isPartnerRecipient() ? data.partner : data.website;
        const priceInput = row.querySelector('.item-price');
        row.querySelector('.item-description').value ||= data.name;
        if (overwritePrice || !priceInput.value) priceInput.value = price;
        priceInput.readOnly = isAdmin && isPartnerRecipient();
        row.querySelector('.price-help').textContent = isAdmin && isPartnerRecipient()
            ? `Harga mitra ${rupiah(data.partner)}`
            : `Minimum jual ${rupiah(data.minimum)}`;
    }

    function bindRow(row) {
        row.querySelector('.package-select').addEventListener('change', () => syncRow(row, true));
        row.querySelector('.remove-item').addEventListener('click', () => {
            if (tbody.querySelectorAll('tr').length > 1) row.remove();
        });
    }

    function syncRecipient(overwritePrices = true) {
        const partner = isPartnerRecipient();
        document.querySelector('.partner-picker')?.classList.toggle('d-none', !partner);
        document.querySelector('.end-user-fields')?.classList.toggle('d-none', partner);
        tbody.querySelectorAll('tr').forEach(row => syncRow(row, overwritePrices));
    }

    document.getElementById('add-invoice-item').addEventListener('click', () => {
        if (tbody.querySelectorAll('tr').length >= 10) return;
        const row = tbody.querySelector('tr').cloneNode(true);
        row.querySelectorAll('input, select').forEach(field => {
            field.name = field.name.replace(/items\[\d+]/, `items[${nextIndex}]`);
            if (field.classList.contains('package-select') || field.classList.contains('item-description') || field.classList.contains('item-price')) field.value = '';
            if (field.classList.contains('item-quantity')) field.value = '1';
        });
        row.querySelector('.price-help').textContent = '';
        nextIndex++;
        tbody.appendChild(row);
        bindRow(row);
    });

    tbody.querySelectorAll('tr').forEach(bindRow);
    recipientType?.addEventListener('change', () => syncRecipient(true));
    syncRecipient(false);
});
</script>
@endpush

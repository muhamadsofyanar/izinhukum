<header class="invoice-head">
    <div>
        <div class="invoice-brand"><span>IH</span><div><strong>IzinHukum</strong><small>{{ config('company.name') }}</small></div></div>
        <p>{{ config('company.address') }}<br>{{ config('company.email') }} · {{ config('company.phone') }}</p>
    </div>
    <div class="invoice-title">
        <span>INVOICE</span>
        <strong>{{ $invoice->invoice_number }}</strong>
        <small>Status: {{ strtoupper($invoice->status) }}</small>
    </div>
</header>
<div class="invoice-meta">
    <div><span>Ditagihkan kepada</span><strong>{{ $invoice->recipient_name }}</strong>@if($invoice->recipient_company)<small>{{ $invoice->recipient_company }}</small>@endif<small>{{ $invoice->recipient_email }}</small><small>{{ $invoice->recipient_phone }}</small><small>{{ $invoice->recipient_address }}</small></div>
    <div><span>Tanggal invoice</span><strong>{{ $invoice->issue_date->translatedFormat('d F Y') }}</strong><span>Jatuh tempo</span><strong>{{ $invoice->due_date?->translatedFormat('d F Y') ?? 'Sesuai kesepakatan' }}</strong></div>
</div>
<div class="table-responsive">
    <table class="invoice-table">
        <thead><tr><th>Layanan</th><th>Jumlah</th><th>Harga</th><th>Total</th></tr></thead>
        <tbody>
        @foreach($invoice->items as $item)
            <tr><td><strong>{{ $item->description }}</strong>@if($item->package?->service)<small>{{ $item->package->service->name }}</small>@endif</td><td>{{ $item->quantity }}</td><td>Rp{{ number_format($item->unit_price, 0, ',', '.') }}</td><td>Rp{{ number_format($item->line_total, 0, ',', '.') }}</td></tr>
        @endforeach
        </tbody>
    </table>
</div>
<div class="invoice-total">
    <span>Total tagihan</span>
    <strong>{{ $invoice->formattedTotal() }}</strong>
</div>
<div class="invoice-foot">
    <div><span>Pembayaran resmi</span><strong>{{ config('company.bank.name') }} {{ config('company.bank.account') }}</strong><small>a.n. {{ config('company.bank.holder') }}</small></div>
    <div>@if($invoice->notes)<span>Catatan</span><p>{{ $invoice->notes }}</p>@endif</div>
</div>
<p class="invoice-disclaimer">Mohon konfirmasi pembayaran hanya melalui kontak resmi IzinHukum. Invoice ini dibuat secara elektronik dan sah tanpa tanda tangan basah.</p>

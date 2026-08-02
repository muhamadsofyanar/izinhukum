<header class="invoice-head">
    <div>
        <div class="invoice-brand">@if($branding['logo'])<img class="invoice-logo" src="{{ asset('storage/'.$branding['logo']) }}" alt="{{ $branding['name'] }}">@else<span>IH</span>@endif<div><strong>{{ $branding['name'] }}</strong><small>{{ $branding['tagline'] }}</small></div></div>
        <p>{{ $branding['address'] }}<br>{{ $branding['email'] }} · {{ $branding['phone'] }}</p>
    </div>
    <div class="invoice-title">
        <span>PENAWARAN</span>
        <strong>{{ $quote->quote_number }}</strong>
        <small>Status: {{ strtoupper($quote->statusLabel()) }}</small>
    </div>
</header>
<div class="invoice-meta">
    <div><span>Ditujukan kepada</span><strong>{{ $quote->recipient_name }}</strong>@if($quote->recipient_company)<small>{{ $quote->recipient_company }}</small>@endif<small>{{ $quote->recipient_email }}</small><small>{{ $quote->recipient_phone }}</small><small>{{ $quote->recipient_address }}</small></div>
    <div><span>Tanggal penawaran</span><strong>{{ $quote->issue_date->translatedFormat('d F Y') }}</strong><span>Berlaku sampai</span><strong>{{ $quote->valid_until->translatedFormat('d F Y') }}</strong></div>
</div>
<div class="table-responsive">
    <table class="invoice-table">
        <thead><tr><th>Layanan</th><th>Jumlah</th><th>Harga</th><th>Total</th></tr></thead>
        <tbody>@foreach($quote->items as $item)<tr><td><strong>{{ $item->description }}</strong>@if($item->package?->service)<small>{{ $item->package->service->name }}</small>@endif</td><td>{{ $item->quantity }}</td><td>Rp{{ number_format($item->unit_price, 0, ',', '.') }}</td><td>Rp{{ number_format($item->line_total, 0, ',', '.') }}</td></tr>@endforeach</tbody>
    </table>
</div>
<div class="invoice-total">
    @if($quote->discount > 0)<div><span>Subtotal</span><strong>Rp{{ number_format($quote->subtotal, 0, ',', '.') }}</strong></div><div><span>Potongan{{ $quote->coupon_code ? ' · '.$quote->coupon_code : '' }}</span><strong>− Rp{{ number_format($quote->discount, 0, ',', '.') }}</strong></div>@endif
    <div><span>Total penawaran</span><strong>{{ $quote->formattedTotal() }}</strong></div>
</div>
@if($quote->scope || $quote->terms)
<div class="quote-details">
    @if($quote->scope)<div><span>Ruang lingkup</span><p>{!! nl2br(e($quote->scope)) !!}</p></div>@endif
    @if($quote->terms)<div><span>Ketentuan</span><p>{!! nl2br(e($quote->terms)) !!}</p></div>@endif
</div>
@endif
<div class="invoice-foot">
    <div><span>Setelah disetujui</span><strong>Invoice dibuat otomatis</strong><small>Jatuh tempo {{ $quote->invoice_due_days }} hari setelah persetujuan.</small></div>
    <div>@if($quote->notes)<span>Catatan</span><p>{{ $quote->notes }}</p>@endif</div>
</div>
<p class="invoice-disclaimer">Penawaran ini dibuat secara elektronik oleh {{ $branding['name'] }} dan hanya berlaku sampai tanggal yang tercantum.</p>

@extends('layouts.admin')

@section('title', $quote->quote_number)
@section('heading', $quote->quote_number)
@section('header_action')<a class="btn btn-outline-primary" href="{{ $shareUrl }}" target="_blank">Buka tautan publik</a>@endsection

@section('content')
<div class="invoice-actions mb-3">
    @if($quote->status === 'draft')<a class="btn btn-primary" href="{{ route('admin.quotes.edit', $quote) }}">Ubah draf</a><form method="post" action="{{ route('admin.quotes.send', $quote) }}" onsubmit="return confirm('Terbitkan penawaran? Data akan dikunci.');">@csrf<button class="btn btn-outline-primary">Terbitkan & kunci</button></form>@endif
    @if(in_array($quote->status, ['draft','sent'], true))<form method="post" action="{{ route('admin.quotes.cancel', $quote) }}" onsubmit="return confirm('Batalkan penawaran ini?');">@csrf<button class="btn btn-outline-danger">Batalkan</button></form>@endif
    @if($quote->invoice)<a class="btn btn-primary" href="{{ route('admin.invoices.show', $quote->invoice) }}">Lihat invoice</a>@endif
</div>
@if($quote->status === 'sent')
<section class="admin-panel p-3 mb-3 quote-share-box"><strong>Tautan siap dikirim melalui WhatsApp</strong><div class="input-group mt-2"><input class="form-control" id="quote-share-url" value="{{ $shareUrl }}" readonly><button class="btn btn-outline-primary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('quote-share-url').value)">Salin</button><a class="btn btn-primary" target="_blank" rel="noopener" href="https://wa.me/{{ preg_replace('/\D/', '', $quote->recipient_phone ?? '') }}?text={{ urlencode('Halo '.$quote->recipient_name.', berikut penawaran '.$quote->quote_number.': '.$shareUrl) }}">Kirim WhatsApp</a></div></section>
@endif
@if($quote->status === 'rejected')<div class="alert alert-warning"><strong>Alasan belum disetujui:</strong> {{ $quote->rejection_reason }}</div>@endif
<section class="invoice-sheet admin-invoice">@include('quotes._document')</section>
@if($quote->inquiry)<div class="admin-note mt-3">Terhubung ke proposal <strong>{{ $quote->inquiry->reference }}</strong>@if($quote->serviceOrder) · order <a href="{{ route('admin.orders.show', $quote->serviceOrder) }}">{{ $quote->serviceOrder->order_number }}</a>@endif.</div>@endif
@endsection

<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $quote->quote_number }} · {{ $branding['name'] }}</title>
    @vite(['resources/css/app.scss', 'resources/js/app.js'])
</head>
<body class="invoice-public-page quote-public-page">
<div class="invoice-toolbar"><a href="{{ route('home') }}">← {{ $branding['name'] }}</a><button class="btn btn-outline-primary" type="button" onclick="window.print()">Cetak / Simpan PDF</button></div>
@if(session('success'))<div class="public-document-alert">{{ session('success') }}</div>@endif
@if($errors->any())<div class="public-document-alert public-document-alert-error"><strong>Periksa kembali:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<main class="invoice-sheet">@include('quotes._document')</main>

<section class="public-document-action">
    @if($quote->status === 'sent' && !$quote->isExpired())
        <h2>Tanggapi penawaran</h2>
        <p>Silakan periksa ruang lingkup, nilai, dan ketentuan. Persetujuan akan membuat invoice resmi secara otomatis.</p>
        <form method="post" action="{{ route('quotes.approve', $quote->public_token) }}" onsubmit="return confirm('Setujui penawaran ini dan buat invoice?');">
            @csrf
            <label class="public-consent"><input type="checkbox" name="approval_confirmation" value="1" required> Saya menyetujui penawaran {{ $quote->quote_number }} senilai {{ $quote->formattedTotal() }}.</label>
            <button class="btn btn-primary" type="submit">Setujui & buat invoice</button>
        </form>
        <details class="public-reject-panel"><summary>Belum dapat menyetujui</summary><form method="post" action="{{ route('quotes.reject', $quote->public_token) }}">@csrf<label class="field"><span>Alasan / bagian yang perlu direvisi</span><textarea class="form-control" name="rejection_reason" rows="3" required minlength="5">{{ old('rejection_reason') }}</textarea></label><button class="btn btn-outline-danger" type="submit">Kirim tanggapan</button></form></details>
    @elseif($quote->status === 'approved' && $quote->invoice)
        <h2>Penawaran telah disetujui</h2><p>Invoice {{ $quote->invoice->invoice_number }} sudah tersedia.</p><a class="btn btn-primary" href="{{ route('invoices.public', $quote->invoice->public_token) }}">Buka invoice & pembayaran</a>
    @elseif($quote->status === 'rejected')
        <h2>Tanggapan sudah diterima</h2><p>Tim {{ $branding['name'] }} akan menghubungi Anda untuk membahas penyesuaian.</p>
    @elseif($quote->isExpired())
        <h2>Masa berlaku berakhir</h2><p>Hubungi tim {{ $branding['name'] }} untuk mendapatkan penawaran yang diperbarui.</p>
    @else
        <h2>Penawaran tidak aktif</h2><p>Dokumen ini sudah tidak dapat ditanggapi.</p>
    @endif
</section>
</body>
</html>

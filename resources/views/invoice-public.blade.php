<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $invoice->invoice_number }} · {{ $branding['name'] }}</title>
    @vite(['resources/css/app.scss', 'resources/js/app.js'])
</head>
<body class="invoice-public-page">
<div class="invoice-toolbar">
    <a href="{{ route('home') }}">← {{ $branding['name'] }}</a>
    <button class="btn btn-primary" type="button" onclick="window.print()">Cetak / Simpan PDF</button>
</div>
<main class="invoice-sheet">
    @include('portal.invoices._document')
</main>
</body>
</html>

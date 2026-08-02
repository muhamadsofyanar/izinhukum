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
@if(session('success'))<div class="public-document-alert">{{ session('success') }}</div>@endif
@if($errors->any())<div class="public-document-alert public-document-alert-error"><strong>Periksa kembali:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<main class="invoice-sheet">
    @include('portal.invoices._document')
</main>
@if($proofUploadEnabled && !in_array($invoice->status, ['draft','cancelled','paid'], true) && $invoice->remainingAmount() > 0)
<section class="public-document-action payment-proof-upload">
    <h2>Konfirmasi transfer</h2>
    <p>Sudah transfer? Unggah JPG, PNG, atau PDF maksimal 5 MB. Admin akan memeriksa sebelum pembayaran dan kwitansi diterbitkan.</p>
    <form class="row g-3" method="post" enctype="multipart/form-data" action="{{ route('invoices.payment-proofs.store', $invoice->public_token) }}">
        @csrf
        <div class="col-md-6"><label class="form-label">Nama pengirim *</label><input class="form-control" name="payer_name" value="{{ old('payer_name', $invoice->recipient_name) }}" required></div>
        <div class="col-md-6"><label class="form-label">Tanggal transfer *</label><input class="form-control" type="date" name="transfer_date" max="{{ now()->format('Y-m-d') }}" value="{{ old('transfer_date', now()->format('Y-m-d')) }}" required></div>
        <div class="col-md-6"><label class="form-label">Nominal transfer *</label><input class="form-control" type="number" min="1" max="{{ $invoice->remainingAmount() }}" name="claimed_amount" value="{{ old('claimed_amount', $invoice->remainingAmount()) }}" required></div>
        <div class="col-md-6"><label class="form-label">Referensi bank</label><input class="form-control" name="bank_reference" value="{{ old('bank_reference') }}"></div>
        <div class="col-md-6"><label class="form-label">Bukti transfer *</label><input class="form-control" type="file" accept="image/jpeg,image/png,application/pdf" name="proof_file" required></div>
        <div class="col-md-6"><label class="form-label">Catatan</label><textarea class="form-control" name="notes" rows="2">{{ old('notes') }}</textarea></div>
        <div><button class="btn btn-primary">Kirim bukti pembayaran</button></div>
    </form>
</section>
@endif
@if($invoice->paymentProofs->isNotEmpty())
<section class="public-proof-status">
    <h2>Status konfirmasi pembayaran</h2>
    @foreach($invoice->paymentProofs as $proof)<div><span class="status status-{{ $proof->status === 'approved' ? 'paid' : ($proof->status === 'rejected' ? 'cancelled' : 'sent') }}">{{ $proof->statusLabel() }}</span><strong>Rp{{ number_format($proof->claimed_amount,0,',','.') }}</strong><small>{{ $proof->created_at->format('d/m/Y H:i') }}@if($proof->status === 'rejected' && $proof->review_note) · {{ $proof->review_note }}@endif</small>@if($proof->payment)<a href="{{ route('receipts.public', $proof->payment->public_token) }}">Buka kwitansi</a>@endif</div>@endforeach
</section>
@endif
</body>
</html>

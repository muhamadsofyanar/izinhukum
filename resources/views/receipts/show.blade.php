<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $payment->receipt_number }} · {{ $branding['name'] }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 24px; color: #07192f; background: #e8eeef; font-family: Arial, sans-serif; }
        .toolbar { display: flex; justify-content: center; margin-bottom: 16px; }
        button { padding: 11px 18px; color: #fff; background: #087864; border: 0; border-radius: 8px; font-weight: 700; cursor: pointer; }
        .receipt { width: min(820px, 100%); margin: auto; padding: 38px 44px; background: #fff; border-top: 9px solid #07192f; box-shadow: 0 18px 55px rgba(7,25,47,.14); }
        .head { display: flex; justify-content: space-between; gap: 24px; padding-bottom: 22px; border-bottom: 1px solid #d9e1e4; }
        .identity { display: flex; align-items: center; gap: 13px; }
        .logo { width: 58px; height: 58px; object-fit: contain; }
        .mark { display: grid; width: 54px; height: 54px; place-items: center; color: #fff; background: #087864; border-radius: 12px; font-weight: 800; }
        .identity strong, .identity small { display: block; }
        .identity small, .contact, .label, .foot { color: #647487; font-size: 12px; line-height: 1.55; }
        .title { text-align: right; }
        .title span { display: block; color: #087864; font-size: 25px; font-weight: 800; letter-spacing: 3px; }
        .title strong { font-size: 13px; }
        .rows { margin: 28px 0; }
        .row { display: grid; grid-template-columns: 180px 1fr; gap: 18px; padding: 11px 0; border-bottom: 1px dashed #d9e1e4; }
        .row strong { line-height: 1.55; }
        .amount { display: flex; align-items: center; justify-content: space-between; gap: 20px; margin: 25px 0; padding: 18px 20px; background: #eef8f5; border-radius: 12px; }
        .amount span { color: #647487; }
        .amount strong { color: #087864; font-size: 28px; }
        .signing { display: flex; justify-content: flex-end; margin-top: 35px; text-align: center; }
        .signatory { position: relative; min-width: 240px; padding-top: 72px; }
        .signature { position: absolute; top: 0; left: 50%; width: 125px; height: 76px; object-fit: contain; transform: translateX(-50%); }
        .stamp { position: absolute; top: 2px; left: 57%; width: 85px; height: 85px; object-fit: contain; opacity: .75; }
        .signatory strong { display: block; padding-top: 7px; border-top: 1px solid #07192f; }
        .void-banner { margin: 24px 0 0; padding: 18px 20px; color: #8b1e25; background: #ffe7e9; border: 2px solid #c73943; border-radius: 10px; text-align: center; }
        .void-banner strong { display: block; font-size: 24px; letter-spacing: 3px; }
        .void-banner span { display: block; margin-top: 6px; font-size: 12px; line-height: 1.5; }
        .receipt.is-cancelled .amount { background: #f4f4f4; }
        .receipt.is-cancelled .amount strong { color: #66717c; text-decoration: line-through; }
        @page { size: A4; margin: 12mm; }
        @media print { body { padding: 0; background: #fff; } .toolbar { display: none; } .receipt { width: 100%; padding: 18px 24px; box-shadow: none; print-color-adjust: exact; -webkit-print-color-adjust: exact; } }
        @media (max-width: 640px) { body { padding: 8px; } .receipt { padding: 24px 20px; } .head { flex-direction: column; } .title { text-align: left; } .row { grid-template-columns: 1fr; gap: 4px; } .amount { align-items: flex-start; flex-direction: column; } }
    </style>
</head>
<body>
    <div class="toolbar"><button type="button" onclick="window.print()">Cetak / Simpan PDF</button></div>
    <main class="receipt {{ $payment->isCancelled() ? 'is-cancelled' : '' }}">
        <header class="head">
            <div>
                <div class="identity">
                    @if($branding['logo'])<img class="logo" src="{{ asset('storage/'.$branding['logo']) }}" alt="{{ $branding['name'] }}">@else<span class="mark">IH</span>@endif
                    <div><strong>{{ $branding['name'] }}</strong><small>{{ $branding['tagline'] }}</small></div>
                </div>
                <p class="contact">{{ $branding['address'] }}<br>{{ $branding['email'] }} · {{ $branding['phone'] }}</p>
            </div>
            <div class="title"><span>KWITANSI</span><strong>{{ $payment->receipt_number }}</strong></div>
        </header>
        @if($payment->isCancelled())
            <div class="void-banner">
                <strong>DIBATALKAN</strong>
                <span>{{ $payment->cancellation_reason }}<br>
                    {{ $payment->cancelled_at?->translatedFormat('d F Y H:i') }}{{ $payment->cancelledBy ? ' · '.$payment->cancelledBy->name : '' }}
                </span>
            </div>
        @endif
        <section class="rows">
            <div class="row"><span class="label">Sudah diterima dari</span><strong>{{ $payment->payer_name ?: $payment->invoice?->recipient_name }}{{ $payment->invoice?->recipient_company ? ' · '.$payment->invoice->recipient_company : '' }}</strong></div>
            <div class="row"><span class="label">Uang sejumlah</span><strong>{{ $amountInWords }}</strong></div>
            <div class="row"><span class="label">Untuk pembayaran</span><strong>{{ $payment->description ?: 'Invoice '.$payment->invoice?->invoice_number }}</strong></div>
            <div class="row"><span class="label">Tanggal pembayaran</span><strong>{{ $payment->payment_date->translatedFormat('d F Y') }}</strong></div>
            <div class="row"><span class="label">Metode dan referensi</span><strong>{{ ucfirst($payment->payment_method) }}{{ $payment->reference_number ? ' · '.$payment->reference_number : '' }}</strong></div>
            @if($payment->notes)<div class="row"><span class="label">Catatan</span><strong>{{ $payment->notes }}</strong></div>@endif
        </section>
        <div class="amount"><span>Jumlah pembayaran</span><strong>{{ $payment->formattedAmount() }}</strong></div>
        <div class="signing"><div class="signatory">
            @if($branding['signature'])<img class="signature" src="{{ asset('storage/'.$branding['signature']) }}" alt="">@endif
            @if($branding['stamp'])<img class="stamp" src="{{ asset('storage/'.$branding['stamp']) }}" alt="">@endif
            <strong>{{ $branding['signatory_name'] }}</strong><span class="foot">{{ $branding['signatory_title'] }}</span>
        </div></div>
        <p class="foot">
            @if($payment->isCancelled())
                Dokumen dipertahankan untuk verifikasi dan jejak audit. Nilainya tidak lagi masuk laporan keuangan.
            @else
                Kwitansi ini dibuat secara elektronik dan dapat diverifikasi melalui tautan unik dokumen.
                @if($payment->last_edited_at) Terakhir dikoreksi {{ $payment->last_edited_at->translatedFormat('d F Y H:i') }}. @endif
            @endif
        </p>
    </main>
</body>
</html>

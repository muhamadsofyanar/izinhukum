<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sertifikat {{ $enrollment->certificate_number }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; color: #07192f; background: #e9eef1; font-family: Arial, sans-serif; }
        .toolbar { display: flex; justify-content: center; gap: 12px; padding: 16px; }
        .toolbar button { padding: 11px 18px; color: #fff; background: #087864; border: 0; border-radius: 8px; font-weight: 700; cursor: pointer; }
        .certificate { position: relative; width: 297mm; min-height: 210mm; margin: 0 auto 24px; padding: 18mm 22mm; overflow: hidden; background: #fff; border: 12px solid #07192f; text-align: center; }
        .certificate::before { position: absolute; inset: 8px; content: ""; border: 2px solid #c6a45b; pointer-events: none; }
        .topline { display: flex; align-items: center; justify-content: center; gap: 14px; }
        .logo { width: 72px; height: 72px; object-fit: contain; }
        .brand-mark { display: grid; width: 68px; height: 68px; place-items: center; color: #fff; background: #087864; border-radius: 14px; font-size: 24px; font-weight: 800; }
        .brand { text-align: left; }
        .brand strong { display: block; font-size: 21px; }
        .brand span { color: #5c6977; font-size: 13px; }
        .eyebrow { margin-top: 22mm; color: #087864; font-size: 13px; font-weight: 800; letter-spacing: 4px; text-transform: uppercase; }
        h1 { margin: 10px 0 24px; font-family: Georgia, serif; font-size: 46px; font-weight: 500; letter-spacing: 1px; }
        .lead { margin: 0; color: #5c6977; font-size: 16px; }
        .participant { display: inline-block; min-width: 65%; margin: 15px 0; padding: 0 20px 8px; border-bottom: 2px solid #c6a45b; font-family: Georgia, serif; font-size: 36px; font-weight: 700; }
        .course { margin: 14px auto 0; max-width: 750px; font-size: 22px; font-weight: 800; }
        .meta { display: flex; justify-content: center; gap: 44px; margin-top: 24px; color: #5c6977; font-size: 13px; }
        .meta strong { display: block; margin-top: 5px; color: #07192f; font-size: 15px; }
        .signing { display: flex; justify-content: center; margin-top: 30px; }
        .signatory { position: relative; min-width: 260px; padding-top: 64px; }
        .signature { position: absolute; top: 0; left: 50%; width: 130px; height: 72px; object-fit: contain; transform: translateX(-50%); }
        .stamp { position: absolute; top: 4px; left: 58%; width: 82px; height: 82px; object-fit: contain; opacity: .75; }
        .signatory strong { display: block; padding-top: 7px; border-top: 1px solid #07192f; }
        .signatory small { color: #5c6977; }
        @page { size: A4 landscape; margin: 0; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .certificate { margin: 0; border-width: 12px; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <div class="toolbar"><button type="button" onclick="window.print()">Cetak / Simpan PDF</button></div>
    <main class="certificate">
        <div class="topline">
            @if($branding['logo'])
                <img class="logo" src="{{ asset('storage/'.$branding['logo']) }}" alt="{{ $branding['name'] }}">
            @else
                <span class="brand-mark">IH</span>
            @endif
            <div class="brand"><strong>{{ $branding['name'] }}</strong><span>{{ $branding['tagline'] }}</span></div>
        </div>
        <div class="eyebrow">Sertifikat Kelulusan</div>
        <h1>Certificate of Completion</h1>
        <p class="lead">Sertifikat ini diberikan kepada</p>
        <div class="participant">{{ $enrollment->user->name }}</div>
        <p class="lead">atas keberhasilan menyelesaikan kelas</p>
        <div class="course">{{ $enrollment->course->title }}</div>
        <div class="meta">
            <div>Tanggal kelulusan<strong>{{ $enrollment->completed_at->timezone('Asia/Jakarta')->translatedFormat('d F Y') }}</strong></div>
            <div>Nomor sertifikat<strong>{{ $enrollment->certificate_number }}</strong></div>
        </div>
        <div class="signing">
            <div class="signatory">
                @if($branding['signature'])<img class="signature" src="{{ asset('storage/'.$branding['signature']) }}" alt="">@endif
                @if($branding['stamp'])<img class="stamp" src="{{ asset('storage/'.$branding['stamp']) }}" alt="">@endif
                <strong>{{ $branding['signatory_name'] }}</strong>
                <small>{{ $branding['signatory_title'] }}</small>
            </div>
        </div>
    </main>
</body>
</html>

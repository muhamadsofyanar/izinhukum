@extends('layouts.app')

@section('title', 'Alat Gratis untuk Memulai Legalitas')
@section('meta_description', 'Coba generator nama, simulasi bahan akta, dan pencarian KBLI sebelum meminta proposal layanan IzinHukum.')

@section('content')
<section class="page-hero page-hero-compact">
    <div class="container">
        <span class="eyebrow">Mulai dengan pengalaman langsung</span>
        <h1>Kenali kebutuhan legalitas sebelum memesan</h1>
        <p>Gunakan alat singkat berikut untuk menyiapkan nama, struktur awal dokumen, dan kegiatan usaha. Hasilnya dapat diteruskan ke tim IzinHukum tanpa mengisi ulang dari awal.</p>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="tool-grid">
            <a class="tool-card" href="{{ route('tools.name-generator') }}">
                <span class="tool-card-number">01</span>
                <small>Mulai dari identitas</small>
                <h2>Generator nama badan</h2>
                <p>Buat alternatif nama PT, Perseroan Perorangan, CV, Firma, Persekutuan Perdata, atau Yayasan dengan pemeriksaan format awal.</p>
                <strong>Coba generator →</strong>
            </a>
            <a class="tool-card" href="{{ route('tools.deed-simulator') }}">
                <span class="tool-card-number">02</span>
                <small>Rasakan alur dokumen</small>
                <h2>Simulasi bahan akta</h2>
                <p>Isi struktur pendiri, kedudukan, kegiatan, modal, dan organ untuk melihat ringkasan bahan pembahasan dengan notaris.</p>
                <strong>Mulai simulasi →</strong>
            </a>
            <a class="tool-card" href="{{ route('kbli.index') }}">
                <span class="tool-card-number">03</span>
                <small>Tentukan kegiatan usaha</small>
                <h2>Cek KBLI dan risiko</h2>
                <p>Cari kode kegiatan, tingkat risiko, perizinan, persyaratan, serta kewajiban berdasarkan katalog KBLI 2025.</p>
                <strong>Cari KBLI →</strong>
            </a>
        </div>

        <div class="journey-panel mt-5">
            <div>
                <span class="eyebrow eyebrow-light">Perjalanan calon klien</span>
                <h2>Dari mencoba hingga ditindaklanjuti</h2>
                <p>Alat publik tidak langsung membuat dokumen hukum. Ketika Anda siap, hasil yang dipilih dibawa ke formulir proposal, dicatat sebagai order, lalu diteruskan ke WhatsApp tim.</p>
            </div>
            <ol>
                <li><span>1</span><strong>Coba alat</strong><small>Kenali kebutuhan dan siapkan data awal.</small></li>
                <li><span>2</span><strong>Minta pemeriksaan</strong><small>Hasil dibawa otomatis ke formulir proposal.</small></li>
                <li><span>3</span><strong>Lanjut via WhatsApp</strong><small>Order tercatat sebelum percakapan dimulai.</small></li>
            </ol>
        </div>
    </div>
</section>
@endsection

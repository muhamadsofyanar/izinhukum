@extends('layouts.app')
@section('title', 'Kebijakan Privasi')
@section('content')
<section class="page-hero page-hero-compact"><div class="container"><span class="eyebrow">Perlindungan data</span><h1>Kebijakan Privasi</h1><p>Ringkasan cara IzinHukum mengelola data calon pelanggan, pelanggan, dan mitra.</p></div></section>
<section class="section"><div class="container legal-copy">
<h2>Data yang dikumpulkan</h2><p>Kami dapat mengumpulkan identitas, informasi kontak, data perusahaan, dokumen legalitas, informasi transaksi, dan komunikasi yang diperlukan untuk konsultasi serta pelaksanaan layanan.</p>
<h2>Tujuan penggunaan</h2><p>Data digunakan untuk menanggapi permintaan, memeriksa kelengkapan, menyiapkan dokumen, menjalankan layanan, membuat invoice, mengirim pembaruan, mengelola kemitraan, dan memenuhi kewajiban hukum.</p>
<h2>Pembagian data</h2><p>Data hanya dibagikan kepada tim, notaris, mitra profesional, penyedia sistem, atau instansi yang relevan sejauh diperlukan untuk layanan dan berdasarkan kewajiban kerahasiaan yang sesuai.</p>
<h2>Keamanan dan penyimpanan</h2><p>Kami menerapkan kontrol akses dan langkah keamanan yang wajar. Data disimpan selama diperlukan untuk layanan, penyelesaian transaksi, kewajiban arsip, dan kepatuhan.</p>
<h2>Hak pemilik data</h2><p>Permintaan akses, koreksi, atau penghapusan yang memungkinkan secara hukum dapat disampaikan melalui {{ config('company.email') }}.</p>
</div></section>
@endsection

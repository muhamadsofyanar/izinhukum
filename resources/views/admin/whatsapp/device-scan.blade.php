@extends('layouts.admin')
@section('title', 'Scan Perangkat WhatsApp')
@section('heading', 'Scan Perangkat StarSender')
@section('content')
@include('admin.whatsapp._nav')

<section class="wa-card wa-qr-card">
    <h2>{{ $deviceName }}</h2>
    <p>{{ $providerMessage }}</p>
    <div class="alert alert-warning">
        QR ini memberi akses untuk menghubungkan perangkat WhatsApp. Jangan membagikan tangkapan layar. Pindai hanya dari perangkat bisnis yang sah, lalu tutup halaman ini.
    </div>
    <div class="wa-qr-wrap">
        <img src="{{ $qrData }}" alt="QR koneksi perangkat StarSender" width="360" height="360">
    </div>
    <ol class="wa-checklist">
        <li>Buka WhatsApp pada ponsel bisnis.</li>
        <li>Pilih Perangkat tertaut, lalu Tautkan perangkat.</li>
        <li>Pindai QR di atas.</li>
        <li>Kembali ke halaman Perangkat dan tekan Sinkronkan perangkat.</li>
    </ol>
    <div class="wa-inline-actions mt-3">
        <a class="btn btn-primary" href="{{ route('admin.whatsapp.devices.index') }}">Selesai, kembali ke perangkat</a>
    </div>
</section>
@endsection

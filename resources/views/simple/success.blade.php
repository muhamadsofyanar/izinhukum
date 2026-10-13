@extends('layouts.app')
@section('title','Pesanan Tercatat')
@section('robots','noindex,nofollow')
@section('content')
<section class="section-padding"><div class="container" style="max-width:760px"><h1>Pesanan tercatat</h1><div class="border rounded-4 p-4 my-4"><p><strong>{{ $inquiry->serviceOrder?->order_number }}</strong></p><p>{{ $inquiry->package?->service?->name }} · {{ $inquiry->package?->name }}</p><p class="mb-0">Harga akhir dan waktu pengerjaan akan dikonfirmasi oleh admin. Pembayaran dilakukan setelah invoice diterbitkan.</p></div><a class="btn btn-success" href="{{ $whatsappUrl }}" target="_blank" rel="noopener">Lanjutkan ke WhatsApp</a><p class="mt-3">Tekan tombol, periksa pesan, lalu kirim melalui WhatsApp Anda.</p></div></section>
@endsection

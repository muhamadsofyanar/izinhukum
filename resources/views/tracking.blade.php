@extends('layouts.app')

@section('title', 'Lacak Permintaan')
@section('meta_description', 'Lacak status permintaan layanan IzinHukum menggunakan nomor referensi dan nomor WhatsApp.')

@section('content')
<section class="page-hero page-hero-compact"><div class="container"><span class="eyebrow">Status layanan</span><h1>Lacak permintaan Anda</h1><p>Masukkan nomor referensi dan nomor WhatsApp yang digunakan saat mengirim proposal.</p></div></section>
<section class="section">
    <div class="container">
        <form class="tracking-form" action="{{ route('tracking.index') }}" method="get">
            <div><label class="form-label">Nomor referensi</label><input class="form-control" name="reference" value="{{ $reference }}" placeholder="IH-260728-ABCDE" required></div>
            <div><label class="form-label">Nomor WhatsApp</label><input class="form-control" name="phone" value="{{ $phone }}" required></div>
            <button class="btn btn-primary" type="submit">Lacak</button>
        </form>
        @if($reference && $phone)
            @if($inquiry)
                <div class="tracking-result">
                    <div><span>Referensi</span><strong>{{ $inquiry->reference }}</strong></div>
                    <div><span>Layanan</span><strong>{{ $inquiry->package?->name ?? 'Konsultasi umum' }}</strong></div>
                    <div><span>Status</span><strong class="status status-{{ $inquiry->status }}">{{ ucfirst($inquiry->status) }}</strong></div>
                    <div><span>Diperbarui</span><strong>{{ $inquiry->updated_at->format('d/m/Y H:i') }} WIB</strong></div>
                </div>
            @else
                <div class="alert alert-warning mt-4">Data tidak ditemukan. Pastikan nomor referensi dan WhatsApp sama persis dengan data pengajuan.</div>
            @endif
        @endif
    </div>
</section>
@endsection

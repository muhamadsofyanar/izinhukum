@extends('layouts.app')

@section('title', 'Lacak Permintaan')
@section('meta_description', 'Lacak status permintaan IzinHukum tanpa menempatkan nomor WhatsApp pada alamat URL.')
@section('content')
<section class="page-hero page-hero-compact"><div class="container"><span class="eyebrow">Status layanan</span><h1>Lacak permintaan Anda</h1><p>Masukkan nomor referensi dan nomor WhatsApp. Data dikirim secara aman melalui formulir POST dan tidak masuk ke alamat URL.</p></div></section>
<section class="section">
    <div class="container">
        <form class="tracking-form" action="{{ route('tracking.search') }}" method="post">
            @csrf
            <div><label class="form-label">Nomor referensi</label><input class="form-control" name="reference" value="{{ old('reference', $reference) }}" placeholder="IH-260728-ABCDE" autocomplete="off" required></div>
            <div><label class="form-label">Nomor WhatsApp</label><input class="form-control" name="phone" value="" autocomplete="off" required></div>
            <button class="btn btn-primary" type="submit">Lacak</button>
        </form>
        @if($errors->any())<div class="alert alert-danger mt-4">Periksa kembali nomor referensi dan WhatsApp.</div>@endif
        @if($searched)
            @if($inquiry)
                <div class="tracking-result">
                    <div><span>Referensi</span><strong>{{ $inquiry->reference }}</strong></div>
                    <div><span>Layanan</span><strong>{{ $inquiry->package?->name ?? 'Konsultasi umum' }}</strong></div>
                    <div><span>Status</span><strong class="status status-{{ $inquiry->status }}">{{ ucfirst($inquiry->status) }}</strong></div>
                    <div><span>Diperbarui</span><strong>{{ $inquiry->updated_at->format('d/m/Y H:i') }} WIB</strong></div>
                </div>
                @if($inquiry->serviceOrder && app(\App\Services\FeatureFlagService::class)->enabled('customer_portal'))
                    <div class="text-center mt-4"><a class="btn btn-primary" href="{{ route('customer.orders.show', $inquiry->serviceOrder->public_token) }}">Buka portal order lengkap</a></div>
                @endif
            @else
                <div class="alert alert-warning mt-4">Data tidak ditemukan. Pastikan nomor referensi dan WhatsApp sama persis dengan data pengajuan.</div>
            @endif
        @endif
    </div>
</section>
@endsection

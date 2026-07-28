@extends('layouts.admin')

@section('title', 'Ringkasan')
@section('heading', 'Ringkasan')

@section('content')
<div class="admin-stats">
    <article><span>Permintaan baru</span><strong>{{ $newInquiries }}</strong></article>
    <article><span>Total permintaan</span><strong>{{ $totalInquiries }}</strong></article>
    <article><span>Layanan aktif</span><strong>{{ $activeServices }}</strong></article>
    <article><span>Harga perkiraan</span><strong>{{ $estimatedPackages }}</strong></article>
</div>

<section class="admin-panel">
    <div class="admin-panel-head"><h2>Permintaan terbaru</h2><a href="{{ route('admin.inquiries.index') }}">Lihat semua →</a></div>
    <div class="table-responsive">
        <table class="table admin-table">
            <thead><tr><th>Referensi</th><th>Nama</th><th>Layanan</th><th>Status</th><th>Tanggal</th></tr></thead>
            <tbody>
                @forelse($latestInquiries as $inquiry)
                    <tr>
                        <td><strong>{{ $inquiry->reference }}</strong></td>
                        <td>{{ $inquiry->name }}<small>{{ $inquiry->phone }}</small></td>
                        <td>{{ $inquiry->package?->name ?? 'Konsultasi umum' }}</td>
                        <td><span class="status status-{{ $inquiry->status }}">{{ ucfirst($inquiry->status) }}</span></td>
                        <td>{{ $inquiry->created_at->format('d/m/Y H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center py-5">Belum ada permintaan.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection

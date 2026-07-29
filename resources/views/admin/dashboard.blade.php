@extends('layouts.admin')

@section('title', 'Ringkasan')
@section('heading', 'Ringkasan Operasional')

@section('header_action')
<a class="btn btn-primary" href="{{ route('admin.orders.create') }}">+ Buat order</a>
@endsection

@section('content')
<div class="admin-stats order-stats">
    <article><span>Order aktif</span><strong>{{ number_format($openOrders, 0, ',', '.') }}</strong></article>
    <article><span>Order terlambat</span><strong>{{ number_format($overdueOrders, 0, ',', '.') }}</strong></article>
    <article><span>Permintaan baru</span><strong>{{ number_format($newInquiries, 0, ',', '.') }}</strong></article>
    <article><span>Invoice belum lunas</span><strong>{{ number_format($unpaidInvoices, 0, ',', '.') }}</strong></article>
    <article><span>Selesai bulan ini</span><strong>{{ number_format($completedOrdersThisMonth, 0, ',', '.') }}</strong></article>
    <article><span>Mitra aktif</span><strong>{{ number_format($activePartners, 0, ',', '.') }}</strong></article>
    <article><span>Pendaftaran mitra</span><strong>{{ number_format($pendingPartners, 0, ',', '.') }}</strong></article>
</div>

<section class="admin-panel">
    <div class="admin-panel-head">
        <h2>Order terbaru</h2>
        <a href="{{ route('admin.orders.index') }}">Lihat semua</a>
    </div>
    <div class="table-responsive">
        <table class="table admin-table align-middle">
            <thead><tr><th>Order</th><th>Pelanggan</th><th>Layanan</th><th>Status</th><th>Pembayaran</th><th>Deadline</th><th></th></tr></thead>
            <tbody>
                @forelse($latestOrders as $order)
                    <tr class="{{ $order->isOverdue() ? 'order-row-overdue' : '' }}">
                        <td><strong>{{ $order->order_number }}</strong><small>{{ $order->created_at->format('d/m/Y H:i') }}</small></td>
                        <td><strong>{{ $order->customer_name }}</strong><small>{{ $order->customer_company ?: ($order->customer_phone ?: $order->customer_email) }}</small></td>
                        <td><strong>{{ $order->title }}</strong><small>{{ $order->package?->name ?: 'Tanpa paket' }}</small></td>
                        <td><span class="status order-status-{{ $order->status }}">{{ $order->statusLabel() }}</span><small>{{ $order->progress }}%</small></td>
                        <td><span class="status payment-status-{{ $order->payment_status }}">{{ $order->paymentStatusLabel() }}</span></td>
                        <td>
                            @if($order->due_at)
                                <strong>{{ $order->due_at->format('d/m/Y') }}</strong>
                                <small class="{{ $order->isOverdue() ? 'text-danger fw-bold' : '' }}">{{ $order->isOverdue() ? 'Terlambat' : $order->due_at->diffForHumans() }}</small>
                            @else
                                <span class="text-muted">Belum ditentukan</span>
                            @endif
                        </td>
                        <td><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.orders.show', $order) }}">Buka</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center py-5">Belum ada order. Gunakan sinkronisasi data lama dari menu Order Layanan.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection

@extends('layouts.admin')

@section('title', 'Order Layanan')
@section('heading', 'Order Layanan')

@section('header_action')
<div class="d-flex flex-wrap gap-2">
    <form action="{{ route('admin.orders.sync') }}" method="post" onsubmit="return confirm('Sinkronkan seluruh permintaan dan invoice lama ke pusat order?')">
        @csrf
        <button class="btn btn-outline-primary" type="submit">Sinkronkan data lama</button>
    </form>
    <a class="btn btn-primary" href="{{ route('admin.orders.create') }}">+ Buat order</a>
</div>
@endsection

@section('content')
<div class="admin-stats order-stats">
    <article><span>Order aktif</span><strong>{{ number_format($summary['open'], 0, ',', '.') }}</strong></article>
    <article><span>Terlambat</span><strong>{{ number_format($summary['overdue'], 0, ',', '.') }}</strong></article>
    <article><span>Belum dibayar</span><strong>{{ number_format($summary['awaiting_payment'], 0, ',', '.') }}</strong></article>
    <article><span>Selesai bulan ini</span><strong>{{ number_format($summary['completed_this_month'], 0, ',', '.') }}</strong></article>
</div>

<section class="admin-panel mb-4">
    <form class="order-filter-form p-3" method="get" action="{{ route('admin.orders.index') }}">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-xl-4">
                <label class="form-label" for="q">Cari order atau pelanggan</label>
                <input class="form-control" id="q" name="q" value="{{ $search }}" placeholder="Nomor order, nama, perusahaan, telepon, email">
            </div>
            <div class="col-6 col-lg-3 col-xl-2">
                <label class="form-label" for="status">Status pekerjaan</label>
                <select class="form-select" id="status" name="status">
                    <option value="">Semua status</option>
                    @foreach(\App\Models\ServiceOrder::STATUSES as $value => $label)
                        <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-lg-3 col-xl-2">
                <label class="form-label" for="payment_status">Pembayaran</label>
                <select class="form-select" id="payment_status" name="payment_status">
                    <option value="">Semua</option>
                    <option value="unpaid" @selected($paymentStatus === 'unpaid')>Belum dibayar</option>
                    <option value="partial" @selected($paymentStatus === 'partial')>Sebagian</option>
                    <option value="paid" @selected($paymentStatus === 'paid')>Lunas</option>
                </select>
            </div>
            <div class="col-6 col-lg-3 col-xl-2">
                <label class="form-label" for="priority">Prioritas</label>
                <select class="form-select" id="priority" name="priority">
                    <option value="">Semua</option>
                    @foreach(\App\Models\ServiceOrder::PRIORITIES as $value => $label)
                        <option value="{{ $value }}" @selected($priority === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-lg-3 col-xl-2 d-flex gap-2">
                <button class="btn btn-primary flex-grow-1" type="submit">Terapkan</button>
                <a class="btn btn-outline-secondary" href="{{ route('admin.orders.index') }}">Reset</a>
            </div>
        </div>
    </form>
</section>

<section class="admin-panel">
    <div class="table-responsive">
        <table class="table admin-table order-table align-middle">
            <thead>
            <tr><th>Order</th><th>Pelanggan</th><th>Layanan</th><th>Status</th><th>Pembayaran</th><th>Deadline</th><th>Petugas</th><th></th></tr>
            </thead>
            <tbody>
            @forelse($orders as $order)
                <tr class="{{ $order->isOverdue() ? 'order-row-overdue' : '' }}">
                    <td>
                        <a href="{{ route('admin.orders.show', $order) }}"><strong>{{ $order->order_number }}</strong></a>
                        <small>{{ $order->created_at->format('d/m/Y H:i') }}</small>
                    </td>
                    <td>
                        <strong>{{ $order->customer_name }}</strong>
                        <small>{{ $order->customer_company ?: ($order->customer_phone ?: $order->customer_email) }}</small>
                    </td>
                    <td>
                        <strong>{{ $order->title }}</strong>
                        <small>{{ $order->package?->name ?: 'Tanpa paket' }}</small>
                    </td>
                    <td>
                        <span class="status order-status-{{ $order->status }}">{{ $order->statusLabel() }}</span>
                        <small>{{ $order->progress }}% · {{ $order->priorityLabel() }}</small>
                    </td>
                    <td><span class="status payment-status-{{ $order->payment_status }}">{{ $order->paymentStatusLabel() }}</span></td>
                    <td>
                        @if($order->due_at)
                            <strong>{{ $order->due_at->format('d/m/Y') }}</strong>
                            <small class="{{ $order->isOverdue() ? 'text-danger fw-bold' : '' }}">{{ $order->isOverdue() ? 'Terlambat' : $order->due_at->diffForHumans() }}</small>
                        @else
                            <span class="text-muted">Belum ditentukan</span>
                        @endif
                    </td>
                    <td>{{ $order->assignee?->name ?: 'Belum ditugaskan' }}</td>
                    <td><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.orders.show', $order) }}">Buka</a></td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center py-5">Belum ada order yang sesuai filter.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($orders->hasPages())
        <div class="p-3">{{ $orders->links() }}</div>
    @endif
</section>
@endsection

@extends('layouts.admin')

@php($prefix = $user->isAdmin() ? 'admin' : 'partner')
@section('title', 'Invoice')
@section('heading', 'Invoice')

@section('header_action')
<a class="btn btn-primary" href="{{ route($prefix.'.invoices.create') }}">Buat invoice</a>
@endsection

@section('content')
<div class="filter-row">
    @foreach(['' => 'Semua', 'draft' => 'Draf', 'sent' => 'Terkirim', 'partial' => 'Dibayar sebagian', 'paid' => 'Lunas', 'cancelled' => 'Dibatalkan'] as $value => $label)
        <a class="{{ (string) $status === $value ? 'active' : '' }}" href="{{ route($prefix.'.invoices.index', $value ? ['status' => $value] : []) }}">{{ $label }}</a>
    @endforeach
</div>
<section class="admin-panel">
    <div class="table-responsive">
        <table class="table admin-table">
            <thead><tr><th>Nomor</th><th>Penerima</th><th>Tipe</th><th>Total</th><th>Status</th><th>Jatuh tempo</th><th></th></tr></thead>
            <tbody>
            @forelse($invoices as $invoice)
                <tr>
                    <td><a href="{{ route($prefix.'.invoices.show', $invoice) }}">{{ $invoice->invoice_number }}</a><small>{{ $invoice->issue_date->format('d/m/Y') }}</small></td>
                    <td><strong>{{ $invoice->recipient_name }}</strong><small>{{ $invoice->recipient_company }}</small><small>{{ $invoice->recipient_email }}</small></td>
                    <td>{{ $invoice->recipient_type === 'partner' ? 'Mitra' : 'End user' }}</td>
                    <td><strong>{{ $invoice->formattedTotal() }}</strong><small>Terbayar Rp{{ number_format($invoice->amount_paid ?? 0, 0, ',', '.') }}</small></td>
                    <td><span class="status status-{{ $invoice->status }}">{{ ucfirst($invoice->status) }}</span></td>
                    <td>{{ $invoice->due_date?->format('d/m/Y') ?? '—' }}</td>
                    <td>
                        @if($invoice->status === 'draft' && ($user->isAdmin() || $invoice->created_by === $user->id))
                            <a class="btn btn-sm btn-outline-primary" href="{{ route($prefix.'.invoices.edit', $invoice) }}">Ubah</a>
                        @else
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route($prefix.'.invoices.show', $invoice) }}">Detail</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center py-5">Belum ada invoice.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="p-3">{{ $invoices->links() }}</div>
</section>
@endsection

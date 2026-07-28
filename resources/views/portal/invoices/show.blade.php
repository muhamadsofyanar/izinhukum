@extends('layouts.admin')

@php($prefix = $user->isAdmin() ? 'admin' : 'partner')
@section('title', $invoice->invoice_number)
@section('heading', $invoice->invoice_number)

@section('header_action')
<a class="btn btn-outline-primary" href="{{ route('invoices.public', $invoice->public_token) }}" target="_blank">Cetak / Simpan PDF</a>
@endsection

@section('content')
<div class="invoice-actions">
    @if($invoice->recipient_email && ($user->isAdmin() || $invoice->created_by === $user->id))
        <form action="{{ route($prefix.'.invoices.send', $invoice) }}" method="post">@csrf<button class="btn btn-primary" type="submit">Kirim melalui email</button></form>
    @endif
    @if($user->isAdmin() || $invoice->created_by === $user->id)
        <form class="d-flex gap-2" action="{{ route($prefix.'.invoices.status', $invoice) }}" method="post">
            @csrf @method('PUT')
            <select class="form-select" name="status">
                @foreach(['draft' => 'Draf', 'sent' => 'Terkirim', 'paid' => 'Lunas', 'cancelled' => 'Dibatalkan'] as $value => $label)
                    <option value="{{ $value }}" @selected($invoice->status === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <button class="btn btn-outline-primary" type="submit">Ubah status</button>
        </form>
    @endif
</div>
<section class="invoice-sheet admin-invoice">
    @include('portal.invoices._document')
</section>
<div class="admin-note mt-3">Tautan publik invoice dapat dibuka penerima tanpa login. Hanya bagikan kepada pihak yang dituju.</div>
@endsection

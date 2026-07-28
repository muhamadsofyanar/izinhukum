@extends('layouts.admin')

@section('title', 'Harga Mitra')
@section('heading', 'Harga Mitra LegaOne')

@section('header_action')
<a class="btn btn-primary" href="{{ route('partner.invoices.create') }}">Buat invoice end user</a>
@endsection

@section('content')
<div class="admin-note">Harga mitra adalah biaya layanan dari IzinHukum. Harga jual kepada pelanggan tidak boleh berada di bawah kolom minimum end user.</div>
<section class="admin-panel">
    <div class="table-responsive">
        <table class="table admin-table partner-price-table">
            <thead><tr><th>Layanan / Paket</th><th>Harga website</th><th>Minimum end user</th><th>Harga mitra</th><th>Potensi margin*</th></tr></thead>
            <tbody>
            @foreach($packages as $package)
                <tr>
                    <td><small>{{ $package->service->short_name }}</small><strong>{{ $package->name }}</strong></td>
                    <td>Rp{{ number_format($package->price, 0, ',', '.') }}</td>
                    <td><strong>Rp{{ number_format($package->minimum_end_user_price, 0, ',', '.') }}</strong></td>
                    <td><strong class="text-success">Rp{{ number_format($package->partner_price, 0, ',', '.') }}</strong></td>
                    <td>Rp{{ number_format(max(0, $package->minimum_end_user_price - $package->partner_price), 0, ',', '.') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</section>
<p class="small text-muted mt-3">*Potensi margin dihitung dari harga jual minimum dikurangi harga mitra, sebelum biaya lain dan pajak yang mungkin berlaku.</p>
@endsection

@extends('layouts.admin')

@section('title', 'Harga & Paket')
@section('heading', 'Harga & Paket')

@section('content')
<div class="admin-note">Urutan harga wajib: <strong>Harga website ≥ harga terendah end user ≥ harga mitra</strong>. Semua nilai ditulis dalam rupiah penuh tanpa titik.</div>
<div class="d-none">
    @foreach($packages as $package)
        <form id="package-form-{{ $package->id }}" action="{{ route('admin.packages.update', $package) }}" method="post">@csrf @method('PUT')</form>
    @endforeach
</div>
<section class="admin-panel">
    <div class="table-responsive">
        <table class="table admin-table package-table">
            <thead><tr><th>Layanan / Paket</th><th>Website</th><th>Minimum end user</th><th>Mitra LegaOne</th><th>Harga coret</th><th>Pengaturan</th><th></th></tr></thead>
            <tbody>
            @foreach($packages as $package)
                <tr>
                    <td><small>{{ $package->service->short_name }}</small><strong>{{ $package->name }}</strong></td>
                    <td><input class="form-control form-control-sm" form="package-form-{{ $package->id }}" name="price" type="number" min="0" value="{{ $package->price }}" required></td>
                    <td><input class="form-control form-control-sm" form="package-form-{{ $package->id }}" name="minimum_end_user_price" type="number" min="0" value="{{ $package->minimum_end_user_price }}" required></td>
                    <td><input class="form-control form-control-sm" form="package-form-{{ $package->id }}" name="partner_price" type="number" min="0" value="{{ $package->partner_price }}" required></td>
                    <td><input class="form-control form-control-sm" form="package-form-{{ $package->id }}" name="original_price" type="number" min="0" value="{{ $package->original_price }}"></td>
                    <td>
                        <label class="check-line"><input form="package-form-{{ $package->id }}" type="checkbox" name="is_estimated" value="1" @checked($package->is_estimated)> Perkiraan</label>
                        <label class="check-line"><input form="package-form-{{ $package->id }}" type="checkbox" name="is_popular" value="1" @checked($package->is_popular)> Populer</label>
                        <label class="check-line"><input form="package-form-{{ $package->id }}" type="checkbox" name="is_active" value="1" @checked($package->is_active)> Aktif</label>
                    </td>
                    <td><button class="btn btn-sm btn-primary" form="package-form-{{ $package->id }}" type="submit">Simpan</button></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</section>
@endsection

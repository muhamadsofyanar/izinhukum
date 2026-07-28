@extends('layouts.admin')

@section('title', 'Harga & Paket')
@section('heading', 'Harga & Paket')

@section('content')
<div class="admin-note">Harga disimpan dalam rupiah penuh tanpa titik. Contoh: <strong>5500000</strong> akan tampil sebagai Rp5.500.000.</div>
<section class="admin-panel">
    <div class="table-responsive">
        <table class="table admin-table package-table">
            <thead><tr><th>Layanan / Paket</th><th>Harga</th><th>Harga coret</th><th>Pengaturan</th><th></th></tr></thead>
            <tbody>
            @foreach($packages as $package)
                <tr>
                    <form action="{{ route('admin.packages.update', $package) }}" method="post">
                        @csrf
                        @method('PUT')
                        <td><small>{{ $package->service->short_name }}</small><strong>{{ $package->name }}</strong></td>
                        <td><input class="form-control form-control-sm" name="price" type="number" min="0" value="{{ $package->price }}" required></td>
                        <td><input class="form-control form-control-sm" name="original_price" type="number" min="0" value="{{ $package->original_price }}"></td>
                        <td>
                            <label class="check-line"><input type="checkbox" name="is_estimated" value="1" @checked($package->is_estimated)> Perkiraan</label>
                            <label class="check-line"><input type="checkbox" name="is_popular" value="1" @checked($package->is_popular)> Populer</label>
                            <label class="check-line"><input type="checkbox" name="is_active" value="1" @checked($package->is_active)> Aktif</label>
                        </td>
                        <td><button class="btn btn-sm btn-primary" type="submit">Simpan</button></td>
                    </form>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</section>
@endsection

@extends('layouts.admin')

@php($prefix = $user->isAdmin() ? 'admin' : 'partner')
@section('title', 'Profil')
@section('heading', 'Profil Saya')

@section('content')
<form class="portal-form" action="{{ route($prefix.'.profile.update') }}" method="post">
    @csrf @method('PUT')
    <section class="admin-panel portal-section">
        <div class="admin-panel-head"><h2>Identitas akun</h2></div>
        <div class="p-4">
            @if($user->partner_code)<div class="admin-note">Kode mitra: <strong>{{ $user->partner_code }}</strong></div>@endif
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Nama *</label><input class="form-control" name="name" value="{{ old('name', $user->name) }}" required></div>
                <div class="col-md-6"><label class="form-label">Email *</label><input class="form-control" name="email" type="email" value="{{ old('email', $user->email) }}" required></div>
                <div class="col-md-6"><label class="form-label">WhatsApp</label><input class="form-control" name="phone" value="{{ old('phone', $user->phone) }}"></div>
                <div class="col-md-6"><label class="form-label">Perusahaan/organisasi</label><input class="form-control" name="company_name" value="{{ old('company_name', $user->company_name) }}"></div>
                <div class="col-md-6"><label class="form-label">NPWP</label><input class="form-control" name="tax_id" value="{{ old('tax_id', $user->tax_id) }}"></div>
                <div class="col-md-6"><label class="form-label">Kota/kabupaten</label><input class="form-control" name="city" value="{{ old('city', $user->city) }}"></div>
                <div class="col-12"><label class="form-label">Alamat</label><textarea class="form-control" name="address" rows="3">{{ old('address', $user->address) }}</textarea></div>
            </div>
        </div>
    </section>
    @if($user->isPartner())
    <section class="admin-panel portal-section mt-3">
        <div class="admin-panel-head"><h2>Rekening komisi</h2></div>
        <div class="p-4 row g-3">
            <div class="col-md-4"><label class="form-label">Nama bank</label><input class="form-control" name="bank_name" value="{{ old('bank_name',$user->bank_name) }}"></div>
            <div class="col-md-4"><label class="form-label">Nomor rekening</label><input class="form-control" name="bank_account_number" value="{{ old('bank_account_number',$user->bank_account_number) }}"></div>
            <div class="col-md-4"><label class="form-label">Nama pemilik rekening</label><input class="form-control" name="bank_account_name" value="{{ old('bank_account_name',$user->bank_account_name) }}"></div>
        </div>
    </section>
    @endif
    <section class="admin-panel portal-section mt-3">
        <div class="admin-panel-head"><h2>Ubah kata sandi (opsional)</h2></div>
        <div class="p-4">
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label">Kata sandi saat ini</label><input class="form-control" name="current_password" type="password"></div>
                <div class="col-md-4"><label class="form-label">Kata sandi baru</label><input class="form-control" name="password" type="password" minlength="10"></div>
                <div class="col-md-4"><label class="form-label">Ulangi kata sandi baru</label><input class="form-control" name="password_confirmation" type="password" minlength="10"></div>
            </div>
        </div>
    </section>
    <button class="btn btn-primary mt-3" type="submit">Simpan profil</button>
</form>
@endsection

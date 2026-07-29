@extends('layouts.admin')

@section('title', 'Buat Order')
@section('heading', 'Buat Order Manual')

@section('header_action')
<a class="btn btn-outline-secondary" href="{{ route('admin.orders.index') }}">Kembali</a>
@endsection

@section('content')
<form action="{{ route('admin.orders.store') }}" method="post">
    @csrf
    <div class="row g-4">
        <div class="col-12 col-xl-7">
            <section class="admin-panel p-4">
                <h2 class="h5 mb-3">Pelanggan dan layanan</h2>
                <div class="row g-3">
                    <div class="col-12"><label class="form-label">Judul order *</label><input class="form-control" name="title" value="{{ old('title') }}" required></div>
                    <div class="col-md-6"><label class="form-label">Nama pelanggan *</label><input class="form-control" name="customer_name" value="{{ old('customer_name') }}" required></div>
                    <div class="col-md-6"><label class="form-label">Perusahaan</label><input class="form-control" name="customer_company" value="{{ old('customer_company') }}"></div>
                    <div class="col-md-6"><label class="form-label">WhatsApp</label><input class="form-control" name="customer_phone" value="{{ old('customer_phone') }}"></div>
                    <div class="col-md-6"><label class="form-label">Email</label><input class="form-control" type="email" name="customer_email" value="{{ old('customer_email') }}"></div>
                    <div class="col-md-6"><label class="form-label">Kota</label><input class="form-control" name="customer_city" value="{{ old('customer_city') }}"></div>
                    <div class="col-12"><label class="form-label">Alamat</label><textarea class="form-control" name="customer_address" rows="2">{{ old('customer_address') }}</textarea></div>
                    <div class="col-12">
                        <label class="form-label">Paket layanan</label>
                        <select class="form-select" name="service_package_id">
                            <option value="">Tanpa paket khusus</option>
                            @foreach($packages->groupBy(fn($package) => $package->service->short_name ?: $package->service->name) as $serviceName => $items)
                                <optgroup label="{{ $serviceName }}">
                                    @foreach($items as $package)
                                        <option value="{{ $package->id }}" @selected(old('service_package_id') == $package->id)>{{ $package->name }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12"><label class="form-label">Ruang lingkup/catatan pelanggan</label><textarea class="form-control" name="description" rows="4">{{ old('description') }}</textarea></div>
                </div>
            </section>
        </div>
        <div class="col-12 col-xl-5">
            <section class="admin-panel p-4">
                <h2 class="h5 mb-3">Penugasan</h2>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Status awal</label>
                        <select class="form-select" name="status">
                            @foreach(\App\Models\ServiceOrder::STATUSES as $value => $label)
                                <option value="{{ $value }}" @selected(old('status', 'lead') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6"><label class="form-label">Prioritas</label><select class="form-select" name="priority">@foreach(\App\Models\ServiceOrder::PRIORITIES as $value => $label)<option value="{{ $value }}" @selected(old('priority', 'normal') === $value)>{{ $label }}</option>@endforeach</select></div>
                    <div class="col-md-6"><label class="form-label">Deadline</label><input class="form-control" type="datetime-local" name="due_at" value="{{ old('due_at') }}"></div>
                    <div class="col-12"><label class="form-label">Petugas</label><select class="form-select" name="assigned_to"><option value="">Belum ditugaskan</option>@foreach($admins as $admin)<option value="{{ $admin->id }}" @selected(old('assigned_to') == $admin->id)>{{ $admin->name }}</option>@endforeach</select></div>
                    <div class="col-12"><label class="form-label">Mitra referral</label><select class="form-select" name="referred_by_partner_id"><option value="">Tanpa referral</option>@foreach($partners as $partner)<option value="{{ $partner->id }}" @selected(old('referred_by_partner_id') == $partner->id)>{{ $partner->partner_code }} · {{ $partner->name }}</option>@endforeach</select></div>
                    <div class="col-12"><label class="form-label">Catatan internal</label><textarea class="form-control" name="internal_notes" rows="5">{{ old('internal_notes') }}</textarea></div>
                </div>
            </section>
        </div>
    </div>
    <div class="d-flex gap-2 mt-4"><button class="btn btn-primary" type="submit">Simpan order</button><a class="btn btn-outline-secondary" href="{{ route('admin.orders.index') }}">Batal</a></div>
</form>
@endsection

@extends('layouts.admin')

@section('title', 'Mitra')
@section('heading', 'Mitra LegaOne')

@php($partnerPlans = config('partner.plans', []))

@section('content')
<section class="admin-panel portal-section">
    <div class="admin-panel-head"><h2>Tambah mitra langsung</h2></div>
    <form class="p-4" action="{{ route('admin.partners.store') }}" method="post">
        @csrf
        <div class="row g-3">
            <div class="col-md-3"><label class="form-label">Nama *</label><input class="form-control" name="name" required></div>
            <div class="col-md-3"><label class="form-label">Email *</label><input class="form-control" name="email" type="email" required></div>
            <div class="col-md-2"><label class="form-label">WhatsApp *</label><input class="form-control" name="phone" required></div>
            <div class="col-md-2"><label class="form-label">Perusahaan</label><input class="form-control" name="company_name"></div>
            <div class="col-md-2"><label class="form-label">Kota</label><input class="form-control" name="city"></div>
            <div class="col-md-3">
                <label class="form-label">Paket mitra *</label>
                <select class="form-select" name="partner_level" required>
                    @foreach($partnerPlans as $code => $plan)
                        <option value="{{ $code }}">{{ $plan['name'] }} · {{ $plan['annual_price'] > 0 ? 'Rp'.number_format($plan['annual_price'], 0, ',', '.').'/tahun' : 'Rp0' }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3"><label class="form-label">Password (opsional)</label><input class="form-control" name="password" type="password" minlength="10" autocomplete="new-password"></div>
            <div class="col-md-3"><label class="form-label">Ulangi password</label><input class="form-control" name="password_confirmation" type="password" minlength="10" autocomplete="new-password"></div>
            <div class="col-12"><button class="btn btn-primary" type="submit">Buat akun mitra</button></div>
        </div>
    </form>
</section>

<section class="admin-panel mt-3">
    <div class="admin-panel-head"><h2>Pendaftaran dari website</h2></div>
    <div class="table-responsive">
        <table class="table admin-table">
            <thead><tr><th>Pendaftar</th><th>Kontak</th><th>Keterangan</th><th>Status</th><th>Tindakan</th></tr></thead>
            <tbody>
            @forelse($applications as $application)
                <tr>
                    <td><strong>{{ $application->name }}</strong><small>{{ $application->reference }}</small><small>{{ $application->company_name }}</small></td>
                    <td><a href="mailto:{{ $application->email }}">{{ $application->email }}</a><a href="https://wa.me/{{ preg_replace('/\D/', '', $application->phone) }}" target="_blank">{{ $application->phone }}</a><small>{{ $application->city }}</small></td>
                    <td class="message-cell">
                        <strong>{{ $partnerPlans[$application->desired_partner_level]['name'] ?? 'Gratis' }}</strong>
                        <small>{{ isset($partnerPlans[$application->desired_partner_level]) && $partnerPlans[$application->desired_partner_level]['annual_price'] > 0 ? 'Rp'.number_format($partnerPlans[$application->desired_partner_level]['annual_price'], 0, ',', '.').'/tahun' : 'Rp0/tahun' }}</small>
                        <span>{{ $application->message ?: '—' }}</span>
                    </td>
                    <td><span class="status status-{{ $application->status }}">{{ ucfirst($application->status) }}</span></td>
                    <td>
                        @if($application->status === 'pending')
                            <form class="mb-2" action="{{ route('admin.partners.approve', $application) }}" method="post">@csrf<button class="btn btn-sm btn-primary w-100" type="submit">Setujui</button></form>
                            <form action="{{ route('admin.partners.reject', $application) }}" method="post">@csrf<input class="form-control form-control-sm mb-2" name="admin_notes" placeholder="Alasan (opsional)"><button class="btn btn-sm btn-outline-danger w-100" type="submit">Tolak</button></form>
                        @else
                            <small>{{ $application->reviewed_at?->format('d/m/Y H:i') }}</small>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center py-5">Belum ada pendaftaran mitra.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="p-3">{{ $applications->links() }}</div>
</section>

<section class="admin-panel mt-3">
    <div class="admin-panel-head"><h2>Daftar akun mitra</h2></div>
    <div class="table-responsive">
        <table class="table admin-table">
            <thead><tr><th>Kode</th><th>Mitra</th><th>Kontak</th><th>Aktivasi</th><th>Level & status</th><th>Atur password</th></tr></thead>
            <tbody>
            @forelse($partners as $partner)
                <tr>
                    <td><strong>{{ $partner->partner_code }}</strong></td>
                    <td><strong>{{ $partner->name }}</strong><small>{{ $partner->company_name }}</small><small>{{ $partner->city }}</small></td>
                    <td><a href="mailto:{{ $partner->email }}">{{ $partner->email }}</a><small>{{ $partner->phone }}</small></td>
                    <td>{{ $partner->email_verified_at ? 'Aktif '.$partner->email_verified_at->format('d/m/Y') : 'Menunggu aktivasi' }}</td>
                    <td>
                        <form action="{{ route('admin.partners.update', $partner) }}" method="post" class="stack-form">@csrf @method('PUT')
                            <select class="form-select form-select-sm" name="partner_level">
                                @foreach($partnerPlans as $level => $plan)
                                    <option value="{{ $level }}" @selected($partner->partner_level===$level)>
                                        {{ $plan['name'] }} · {{ $plan['annual_price'] > 0 ? 'Rp'.number_format($plan['annual_price'], 0, ',', '.').'/tahun' : 'Rp0' }}
                                    </option>
                                @endforeach
                            </select>
                            <select class="form-select form-select-sm" name="account_status">@foreach(['pending','active','suspended','inactive'] as $status)<option @selected($partner->account_status===$status)>{{ $status }}</option>@endforeach</select>
                            <button class="btn btn-sm btn-secondary" type="submit">Simpan</button>
                        </form>
                    </td>
                    <td>
                        <form action="{{ route('admin.partners.password', $partner) }}" method="post" class="stack-form">@csrf @method('PUT')
                            <input class="form-control form-control-sm" name="password" type="password" minlength="10" placeholder="Password baru" required>
                            <input class="form-control form-control-sm" name="password_confirmation" type="password" minlength="10" placeholder="Ulangi password" required>
                            <button class="btn btn-sm btn-outline-primary" type="submit">Simpan password</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center py-5">Belum ada akun mitra.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="p-3">{{ $partners->links() }}</div>
</section>
@endsection

@extends('layouts.admin')

@section('title', 'Kupon & Promo')
@section('heading', 'Kupon & Promo')

@section('content')
<div class="admin-note mb-3">
    Kupon memberikan diskon kepada klien. Referral tetap berdiri sendiri untuk mencatat sumber dan komisi mitra.
</div>

<details class="admin-panel mb-3" @if($errors->any()) open @endif>
    <summary class="admin-panel-head"><h2>Buat kupon</h2><span>Tambah promo baru</span></summary>
    <form class="p-4 stack-form" action="{{ route('admin.coupons.store') }}" method="post">
        @csrf
        @include('admin.partials.coupon-fields', ['coupon' => null, 'formId' => 'coupon-new'])
        <button class="btn btn-primary" type="submit">Simpan kupon</button>
    </form>
</details>

<section class="admin-panel">
    <div class="admin-panel-head"><h2>Daftar kupon</h2><strong>{{ $coupons->count() }} kupon</strong></div>
    <div class="table-responsive">
        <table class="table admin-table align-middle">
            <thead><tr><th>Kode</th><th>Promo</th><th>Layanan</th><th>Masa berlaku</th><th>Penggunaan</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($coupons as $coupon)
                <tr>
                    <td><strong>{{ $coupon->code }}</strong><small>{{ $coupon->name }}</small></td>
                    <td>{{ $coupon->discountLabel() }}@if($coupon->maximum_discount)<small>Maks. Rp{{ number_format($coupon->maximum_discount, 0, ',', '.') }}</small>@endif</td>
                    <td>
                        @if($coupon->applies_to_all_services)
                            Semua layanan
                        @else
                            {{ $coupon->services->pluck('name')->join(', ') }}
                        @endif
                    </td>
                    <td>
                        {{ $coupon->starts_at?->format('d/m/Y H:i') ?: 'Sekarang' }}
                        <small>s.d. {{ $coupon->ends_at?->format('d/m/Y H:i') ?: 'tanpa batas' }}</small>
                    </td>
                    <td>{{ $coupon->redemptions_count }}@if($coupon->maximum_redemptions) / {{ $coupon->maximum_redemptions }}@endif</td>
                    <td><span class="status status-{{ $coupon->is_active ? 'paid' : 'cancelled' }}">{{ $coupon->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                    <td>
                        <details>
                            <summary class="btn btn-sm btn-outline-primary">Ubah</summary>
                            <div class="coupon-edit-panel mt-2">
                                <form class="stack-form" action="{{ route('admin.coupons.update', $coupon) }}" method="post">
                                    @csrf @method('PUT')
                                    @include('admin.partials.coupon-fields', ['coupon' => $coupon, 'formId' => 'coupon-'.$coupon->id])
                                    <button class="btn btn-primary btn-sm" type="submit">Simpan perubahan</button>
                                </form>
                                @if($coupon->redemptions_count === 0)
                                    <form class="mt-2" action="{{ route('admin.coupons.destroy', $coupon) }}" method="post" onsubmit="return confirm('Hapus kupon ini?');">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-outline-danger btn-sm" type="submit">Hapus</button>
                                    </form>
                                @endif
                            </div>
                        </details>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center py-5">Belum ada kupon. Buat promo pertama melalui formulir di atas.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection

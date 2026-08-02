<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\Inquiry;
use App\Models\ServicePackage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CouponService
{
    public function quote(string $code, ?ServicePackage $package, bool $lockForUpdate = false): array
    {
        $normalized = Str::upper(trim($code));
        if ($normalized === '') {
            throw ValidationException::withMessages(['coupon_code' => 'Masukkan kode kupon.']);
        }
        if (! $package || ! $package->is_active || ! $package->service?->is_active) {
            throw ValidationException::withMessages(['coupon_code' => 'Pilih paket layanan aktif sebelum memakai kupon.']);
        }

        $query = Coupon::query()->where('code', $normalized);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $coupon = $query->first();

        if (! $coupon || ! $coupon->is_active) {
            throw ValidationException::withMessages(['coupon_code' => 'Kode kupon tidak ditemukan atau sudah dinonaktifkan.']);
        }
        if ($coupon->starts_at && $coupon->starts_at->isFuture()) {
            throw ValidationException::withMessages(['coupon_code' => 'Kupon belum memasuki masa berlaku.']);
        }
        if ($coupon->ends_at && $coupon->ends_at->isPast()) {
            throw ValidationException::withMessages(['coupon_code' => 'Masa berlaku kupon sudah berakhir.']);
        }
        if (
            $coupon->maximum_redemptions !== null
            && $coupon->redemptions()->count() >= $coupon->maximum_redemptions
        ) {
            throw ValidationException::withMessages(['coupon_code' => 'Kuota penggunaan kupon sudah habis.']);
        }
        if (
            ! $coupon->applies_to_all_services
            && ! $coupon->services()->whereKey($package->service_id)->exists()
        ) {
            throw ValidationException::withMessages(['coupon_code' => 'Kupon tidak berlaku untuk layanan yang dipilih.']);
        }

        $subtotal = (int) $package->price;
        if ($subtotal <= 0) {
            throw ValidationException::withMessages([
                'coupon_code' => 'Kupon belum dapat dihitung karena harga paket masih berdasarkan penawaran. Tim akan mengonfirmasi promo pada penawaran final.',
            ]);
        }
        if ($subtotal < (int) $coupon->minimum_subtotal) {
            throw ValidationException::withMessages([
                'coupon_code' => 'Kupon memerlukan nilai layanan minimal Rp'
                    .number_format($coupon->minimum_subtotal, 0, ',', '.').'.',
            ]);
        }

        $discount = $coupon->discount_type === 'percentage'
            ? (int) floor($subtotal * $coupon->discount_value / 100)
            : (int) $coupon->discount_value;
        if ($coupon->maximum_discount !== null) {
            $discount = min($discount, (int) $coupon->maximum_discount);
        }
        $discount = max(0, min($subtotal, $discount));

        return [
            'coupon' => $coupon,
            'code' => $coupon->code,
            'discount_type' => $coupon->discount_type,
            'discount_value' => (int) $coupon->discount_value,
            'discount_amount' => $discount,
            'subtotal' => $subtotal,
            'total' => max(0, $subtotal - $discount),
        ];
    }

    public function redeem(array $quote, Inquiry $inquiry): void
    {
        $quote['coupon']->redemptions()->create([
            'inquiry_id' => $inquiry->id,
            'coupon_code' => $quote['code'],
            'discount_amount' => $quote['discount_amount'],
            'redeemed_at' => now(),
        ]);
    }
}

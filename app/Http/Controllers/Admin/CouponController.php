<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CouponController extends Controller
{
    public function index(): View
    {
        return view('admin.coupons', [
            'coupons' => Coupon::query()
                ->with(['services:id,name'])
                ->withCount('redemptions')
                ->latest()
                ->get(),
            'services' => Service::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $coupon = Coupon::query()->create([
            ...$data['attributes'],
            'created_by' => $request->attributes->get('currentUser')?->id,
        ]);
        $coupon->services()->sync($data['service_ids']);

        return back()->with('success', 'Kupon '.$coupon->code.' berhasil dibuat.');
    }

    public function update(Request $request, Coupon $coupon): RedirectResponse
    {
        $data = $this->validated($request, $coupon);
        $coupon->update($data['attributes']);
        $coupon->services()->sync($data['service_ids']);

        return back()->with('success', 'Kupon '.$coupon->code.' berhasil diperbarui.');
    }

    public function destroy(Coupon $coupon): RedirectResponse
    {
        if ($coupon->redemptions()->exists()) {
            throw ValidationException::withMessages([
                'coupon' => 'Kupon yang sudah digunakan tidak dapat dihapus. Nonaktifkan agar histori promo tetap utuh.',
            ]);
        }
        $code = $coupon->code;
        $coupon->delete();

        return back()->with('success', 'Kupon '.$code.' berhasil dihapus.');
    }

    private function validated(Request $request, ?Coupon $coupon = null): array
    {
        $request->merge(['code' => Str::upper(trim((string) $request->input('code')))]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => [
                'required',
                'string',
                'min:3',
                'max:32',
                'regex:/^[A-Z0-9][A-Z0-9_-]+$/',
                Rule::unique('coupons', 'code')->ignore($coupon),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'discount_type' => ['required', Rule::in(['percentage', 'fixed'])],
            'discount_value' => ['required', 'integer', 'min:1'],
            'maximum_discount' => ['nullable', 'integer', 'min:1'],
            'minimum_subtotal' => ['nullable', 'integer', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => array_values(array_filter([
                'nullable',
                'date',
                $request->filled('starts_at') ? 'after_or_equal:starts_at' : null,
            ])),
            'maximum_redemptions' => ['nullable', 'integer', 'min:1'],
            'applies_to_all_services' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'service_ids' => ['nullable', 'array'],
            'service_ids.*' => ['integer', 'exists:services,id'],
        ], [
            'code.regex' => 'Kode hanya boleh memakai huruf kapital, angka, tanda hubung, atau garis bawah.',
        ]);

        if ($validated['discount_type'] === 'percentage' && (int) $validated['discount_value'] > 100) {
            throw ValidationException::withMessages(['discount_value' => 'Diskon persentase maksimal 100%.']);
        }

        $allServices = $request->boolean('applies_to_all_services');
        $serviceIds = $allServices ? [] : array_values(array_unique($validated['service_ids'] ?? []));
        if (! $allServices && $serviceIds === []) {
            throw ValidationException::withMessages(['service_ids' => 'Pilih minimal satu layanan atau aktifkan semua layanan.']);
        }

        return [
            'attributes' => [
                'name' => $validated['name'],
                'code' => $validated['code'],
                'description' => $validated['description'] ?? null,
                'discount_type' => $validated['discount_type'],
                'discount_value' => (int) $validated['discount_value'],
                'maximum_discount' => $validated['maximum_discount'] ?? null,
                'minimum_subtotal' => $validated['minimum_subtotal'] ?? 0,
                'starts_at' => $validated['starts_at'] ?? null,
                'ends_at' => $validated['ends_at'] ?? null,
                'maximum_redemptions' => $validated['maximum_redemptions'] ?? null,
                'applies_to_all_services' => $allServices,
                'is_active' => $request->boolean('is_active'),
            ],
            'service_ids' => $serviceIds,
        ];
    }
}

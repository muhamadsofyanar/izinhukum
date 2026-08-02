@php
    $selectedServices = old('service_ids', $coupon?->services?->pluck('id')->all() ?? []);
    $allServices = (bool) old('applies_to_all_services', $coupon?->applies_to_all_services ?? false);
@endphp
<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label" for="{{ $formId }}-name">Nama promo *</label>
        <input class="form-control" id="{{ $formId }}-name" name="name" value="{{ old('name', $coupon?->name) }}" maxlength="120" required>
    </div>
    <div class="col-md-3">
        <label class="form-label" for="{{ $formId }}-code">Kode kupon *</label>
        <input class="form-control text-uppercase" id="{{ $formId }}-code" name="code" value="{{ old('code', $coupon?->code) }}" maxlength="32" required placeholder="LEGAL10">
    </div>
    <div class="col-md-3">
        <label class="form-label" for="{{ $formId }}-type">Jenis diskon *</label>
        <select class="form-select" id="{{ $formId }}-type" name="discount_type" required>
            <option value="percentage" @selected(old('discount_type', $coupon?->discount_type ?? 'percentage') === 'percentage')>Persentase (%)</option>
            <option value="fixed" @selected(old('discount_type', $coupon?->discount_type) === 'fixed')>Nominal (Rp)</option>
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label" for="{{ $formId }}-value">Nilai diskon *</label>
        <input class="form-control" id="{{ $formId }}-value" name="discount_value" type="number" min="1" value="{{ old('discount_value', $coupon?->discount_value) }}" required>
    </div>
    <div class="col-md-3">
        <label class="form-label" for="{{ $formId }}-maximum">Maksimum potongan</label>
        <input class="form-control" id="{{ $formId }}-maximum" name="maximum_discount" type="number" min="1" value="{{ old('maximum_discount', $coupon?->maximum_discount) }}">
    </div>
    <div class="col-md-3">
        <label class="form-label" for="{{ $formId }}-minimum">Minimum nilai layanan</label>
        <input class="form-control" id="{{ $formId }}-minimum" name="minimum_subtotal" type="number" min="0" value="{{ old('minimum_subtotal', $coupon?->minimum_subtotal ?? 0) }}">
    </div>
    <div class="col-md-3">
        <label class="form-label" for="{{ $formId }}-quota">Maksimum penggunaan</label>
        <input class="form-control" id="{{ $formId }}-quota" name="maximum_redemptions" type="number" min="1" value="{{ old('maximum_redemptions', $coupon?->maximum_redemptions) }}">
    </div>
    <div class="col-md-3">
        <label class="form-label" for="{{ $formId }}-start">Mulai berlaku</label>
        <input class="form-control" id="{{ $formId }}-start" name="starts_at" type="datetime-local" value="{{ old('starts_at', $coupon?->starts_at?->format('Y-m-d\TH:i')) }}">
    </div>
    <div class="col-md-3">
        <label class="form-label" for="{{ $formId }}-end">Berakhir</label>
        <input class="form-control" id="{{ $formId }}-end" name="ends_at" type="datetime-local" value="{{ old('ends_at', $coupon?->ends_at?->format('Y-m-d\TH:i')) }}">
    </div>
    <div class="col-12">
        <label class="form-label" for="{{ $formId }}-description">Keterangan</label>
        <textarea class="form-control" id="{{ $formId }}-description" name="description" rows="2" maxlength="1000">{{ old('description', $coupon?->description) }}</textarea>
    </div>
    <div class="col-12">
        <label class="check-line"><input type="checkbox" name="applies_to_all_services" value="1" @checked($allServices)> Berlaku untuk semua layanan</label>
        <div class="coupon-service-grid mt-2">
            @foreach($services as $service)
                <label class="check-line"><input type="checkbox" name="service_ids[]" value="{{ $service->id }}" @checked(in_array($service->id, $selectedServices))> {{ $service->name }}</label>
            @endforeach
        </div>
    </div>
    <div class="col-12">
        <label class="check-line"><input type="checkbox" name="is_active" value="1" @checked((bool) old('is_active', $coupon?->is_active ?? true))> Kupon aktif</label>
    </div>
</div>

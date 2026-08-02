@extends('layouts.admin')

@section('title', 'Campaign & Landing Page')
@section('heading', 'Campaign, Landing Page & ROI')
@section('header_action')
    @if($growthEnabled)
        <a class="btn btn-outline-primary" href="{{ route('admin.growth.index') }}">Buka analitik funnel</a>
    @endif
@endsection

@section('content')
<div class="admin-note mb-3">
    Buat tautan landing page untuk broadcast WhatsApp. Calon klien mengisi form singkat, memperoleh nomor referensi, lalu melanjutkan deal secara manual melalui WhatsApp.
    @if(! $landingPagesEnabled)
        <strong>Publikasi landing page sedang dimatikan pada Fitur aplikasi.</strong>
    @endif
</div>

<details class="admin-panel mb-3" {{ $errors->any() ? 'open' : '' }}>
    <summary class="admin-panel-head"><h2>Buat campaign dan landing page</h2><span>Simpan lalu salin tautan broadcast</span></summary>
    <form class="p-4 form-grid" method="post" action="{{ route('admin.marketing-campaigns.store') }}">
        @csrf
        <label class="field"><span>Nama campaign *</span><input class="form-control" name="name" value="{{ old('name') }}" required></label>
        <label class="field"><span>Kode URL</span><input class="form-control" name="slug" value="{{ old('slug') }}" placeholder="Otomatis dari nama"></label>
        <label class="field"><span>Fokus layanan</span><select class="form-select" name="service_id"><option value="">Semua layanan</option>@foreach($services as $service)<option value="{{ $service->id }}" @selected(old('service_id') == $service->id)>{{ $service->name }}</option>@endforeach</select></label>
        <label class="field"><span>Promo/kupon otomatis</span><select class="form-select" name="coupon_id"><option value="">Tanpa promo</option>@foreach($coupons as $coupon)<option value="{{ $coupon->id }}" @selected(old('coupon_id') == $coupon->id)>{{ $coupon->code }} · {{ $coupon->name }}{{ $coupon->is_active ? '' : ' (nonaktif)' }}</option>@endforeach</select></label>
        <label class="field"><span>Teks tombol</span><input class="form-control" name="cta_text" value="{{ old('cta_text', 'Konsultasi sekarang') }}" required></label>
        <label class="field"><span>Sumber</span><input class="form-control" name="source" value="{{ old('source', 'whatsapp') }}" required></label>
        <label class="field"><span>Media</span><input class="form-control" name="medium" value="{{ old('medium', 'broadcast') }}" required></label>
        <label class="field field-wide"><span>Judul landing page</span><input class="form-control" name="landing_headline" value="{{ old('landing_headline') }}" placeholder="Kosongkan untuk memakai judul bawaan yang berorientasi konversi"></label>
        <label class="field field-wide"><span>Penjelasan singkat landing page</span><textarea class="form-control" name="landing_subheadline" rows="3" placeholder="Masalah utama, hasil yang ditawarkan, dan alasan calon klien perlu mengisi form.">{{ old('landing_subheadline') }}</textarea></label>
        <label class="field"><span>Tanggal mulai</span><input class="form-control" type="date" name="start_date" value="{{ old('start_date', now()->format('Y-m-d')) }}"></label>
        <label class="field"><span>Tanggal selesai</span><input class="form-control" type="date" name="end_date" value="{{ old('end_date') }}"></label>
        <label class="field"><span>Budget</span><input class="form-control" type="number" min="0" name="budget" value="{{ old('budget', 0) }}"></label>
        <label class="field"><span>Biaya aktual</span><input class="form-control" type="number" min="0" name="spend" value="{{ old('spend', 0) }}"></label>
        <label class="field"><span>Status</span><select class="form-select" name="status">@foreach($statuses as $key=>$label)<option value="{{ $key }}" @selected(old('status', 'active') === $key)>{{ $label }}</option>@endforeach</select></label>
        <label class="check-field"><input type="hidden" name="is_landing_enabled" value="0"><input type="checkbox" name="is_landing_enabled" value="1" @checked(old('is_landing_enabled', '1') === '1')> Landing page aktif</label>
        <label class="field field-wide"><span>Catatan internal</span><textarea class="form-control" name="notes" rows="2">{{ old('notes') }}</textarea></label>
        <div><button class="btn btn-primary">Simpan campaign</button></div>
    </form>
</details>

<div class="campaign-grid">
@forelse($campaigns as $campaign)
    @php($landingConversion = $campaign->landing_views > 0 ? round(($campaign->inquiries_count / $campaign->landing_views) * 100, 1) : 0)
    <article class="admin-panel">
        <form class="p-3 stack-form" method="post" action="{{ route('admin.marketing-campaigns.update', $campaign) }}">
            @csrf @method('PUT')
            <div class="campaign-admin-stats">
                <span class="status status-{{ $campaign->status === 'active' ? 'paid' : 'draft' }}">{{ $campaign->statusLabel() }}</span>
                <span><strong>{{ number_format($campaign->landing_views) }}</strong> kunjungan</span>
                <span><strong>{{ number_format($campaign->inquiries_count) }}</strong> lead</span>
                <span><strong>{{ number_format($landingConversion, 1, ',', '.') }}%</strong> konversi form</span>
            </div>
            <label class="field"><span>Nama</span><input class="form-control" name="name" value="{{ $campaign->name }}" required></label>
            <div class="row g-2">
                <label class="field col-md-6"><span>Kode URL</span><input class="form-control" name="slug" value="{{ $campaign->slug }}" required></label>
                <label class="field col-md-6"><span>Fokus layanan</span><select class="form-select" name="service_id"><option value="">Semua layanan</option>@foreach($services as $service)<option value="{{ $service->id }}" @selected($campaign->service_id === $service->id)>{{ $service->name }}</option>@endforeach</select></label>
            </div>
            <label class="field"><span>Promo/kupon otomatis</span><select class="form-select" name="coupon_id"><option value="">Tanpa promo</option>@foreach($coupons as $coupon)<option value="{{ $coupon->id }}" @selected($campaign->coupon_id === $coupon->id)>{{ $coupon->code }} · {{ $coupon->name }}{{ $coupon->is_active ? '' : ' (nonaktif)' }}</option>@endforeach</select></label>
            <div class="row g-2">
                <label class="field col-6"><span>Sumber</span><input class="form-control" name="source" value="{{ $campaign->source }}" required></label>
                <label class="field col-6"><span>Media</span><input class="form-control" name="medium" value="{{ $campaign->medium }}" required></label>
            </div>
            <label class="field"><span>Judul landing page</span><input class="form-control" name="landing_headline" value="{{ $campaign->landing_headline }}" placeholder="Judul bawaan jika kosong"></label>
            <label class="field"><span>Penjelasan landing page</span><textarea class="form-control" name="landing_subheadline" rows="3">{{ $campaign->landing_subheadline }}</textarea></label>
            <label class="field"><span>Teks tombol</span><input class="form-control" name="cta_text" value="{{ $campaign->cta_text }}" required></label>
            <div class="row g-2">
                <label class="field col-6"><span>Mulai</span><input class="form-control" type="date" name="start_date" value="{{ $campaign->start_date?->format('Y-m-d') }}"></label>
                <label class="field col-6"><span>Selesai</span><input class="form-control" type="date" name="end_date" value="{{ $campaign->end_date?->format('Y-m-d') }}"></label>
            </div>
            <div class="row g-2">
                <label class="field col-6"><span>Budget</span><input class="form-control" type="number" min="0" name="budget" value="{{ $campaign->budget }}"></label>
                <label class="field col-6"><span>Biaya aktual</span><input class="form-control" type="number" min="0" name="spend" value="{{ $campaign->spend }}"></label>
            </div>
            <div class="row g-2 align-items-end">
                <label class="field col-md-7"><span>Status</span><select class="form-select" name="status">@foreach($statuses as $key=>$label)<option value="{{ $key }}" @selected($campaign->status === $key)>{{ $label }}</option>@endforeach</select></label>
                <label class="check-field col-md-5"><input type="hidden" name="is_landing_enabled" value="0"><input type="checkbox" name="is_landing_enabled" value="1" @checked($campaign->is_landing_enabled)> Landing aktif</label>
            </div>
            <label class="field"><span>Catatan</span><textarea class="form-control" name="notes" rows="2">{{ $campaign->notes }}</textarea></label>
            <button class="btn btn-primary">Simpan perubahan</button>

            <div class="campaign-link-builder">
                <select class="form-select form-select-sm campaign-target">
                    <option value="{{ route('campaigns.landing', $campaign) }}">Landing campaign (disarankan)</option>
                    @foreach($services as $service)<option value="{{ route('services.show', $service) }}">Halaman {{ $service->name }}</option>@endforeach
                    <option value="{{ route('proposal.create') }}">Form proposal umum</option>
                </select>
                <input class="form-control form-control-sm campaign-link" data-source="{{ $campaign->source }}" data-medium="{{ $campaign->medium }}" data-campaign="{{ $campaign->slug }}" readonly>
                <button class="btn btn-sm btn-outline-primary copy-campaign-link" type="button">Salin tautan broadcast</button>
                @if($landingPagesEnabled && $campaign->isLandingLive())
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('campaigns.landing', $campaign) }}" target="_blank" rel="noopener">Pratinjau ↗</a>
                @else
                    <small>Landing page belum dapat dibuka sampai fitur, status, periode, dan sakelar landing aktif.</small>
                @endif
            </div>
        </form>
    </article>
@empty
    <div class="empty-state"><h2>Belum ada campaign</h2><p>Buat campaign pertama untuk memperoleh landing page dan mengukur kunjungan menjadi lead.</p></div>
@endforelse
</div>
@if($campaigns->hasPages())
    <div class="mt-3">{{ $campaigns->links() }}</div>
@endif
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.campaign-link-builder').forEach(box => {
        const target = box.querySelector('.campaign-target');
        const output = box.querySelector('.campaign-link');
        const build = () => {
            const url = new URL(target.value);
            url.searchParams.set('utm_source', output.dataset.source);
            url.searchParams.set('utm_medium', output.dataset.medium);
            url.searchParams.set('utm_campaign', output.dataset.campaign);
            output.value = url.toString();
        };
        target.addEventListener('change', build);
        box.querySelector('.copy-campaign-link').addEventListener('click', async event => {
            await navigator.clipboard.writeText(output.value);
            const original = event.currentTarget.textContent;
            event.currentTarget.textContent = 'Tersalin';
            window.setTimeout(() => event.currentTarget.textContent = original, 1200);
        });
        build();
    });
});
</script>
@endpush

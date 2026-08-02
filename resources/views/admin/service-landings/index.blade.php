@extends('layouts.admin')

@section('title', 'Landing Layanan')
@section('heading', 'Landing Layanan V21')
@section('header_action')<a class="btn btn-outline-primary btn-sm" href="{{ route('services.index') }}" target="_blank" rel="noopener">Lihat semua layanan ↗</a>@endsection

@section('content')
<div class="admin-note"><strong>Standar V21 aktif untuk semua layanan.</strong> Judul, manfaat, tahapan, FAQ, dan SEO dapat disesuaikan per layanan. Pisahkan judul dan penjelasan dengan tanda <code>|</code>.</div>
<div class="service-landing-admin-summary">
    <article><strong>{{ $services->count() }}</strong><span>Total layanan</span></article>
    <article><strong>{{ $services->where('is_active', true)->count() }}</strong><span>Landing aktif</span></article>
    <article><strong>{{ $services->sum(fn($service) => $service->packages->count()) }}</strong><span>Paket ditampilkan</span></article>
</div>

<section class="service-landing-admin-list">
@foreach($services as $service)
    @php($content = $contents[$service->id])
    <details class="admin-panel service-landing-editor" id="service-{{ $service->slug }}" @if(request('open') === $service->slug) open @endif>
        <summary>
            <span><small>{{ $service->category }}</small><strong>{{ $service->name }}</strong></span>
            <span class="service-landing-editor-meta"><span class="status status-{{ $service->is_active ? 'paid' : 'cancelled' }}">{{ $service->is_active ? 'Aktif' : 'Nonaktif' }}</span><span>{{ $service->packages->count() }} paket</span><b>Ubah</b></span>
        </summary>
        <form class="service-landing-editor-form" action="{{ route('admin.service-landings.update', $service) }}" method="post">
            @csrf @method('put')
            <div class="service-landing-editor-toolbar">
                <p>URL: <code>/layanan/{{ $service->slug }}</code></p>
                @if($service->is_active)<a href="{{ route('services.show', $service) }}" target="_blank" rel="noopener">Pratinjau landing ↗</a>@endif
            </div>
            <div class="form-grid">
                <label class="field"><span>Label kategori</span><input class="form-control" name="landing_eyebrow" maxlength="120" value="{{ old('landing_eyebrow', $content['eyebrow']) }}"></label>
                <label class="field"><span>Judul SEO <small>(maks. 70)</small></span><input class="form-control" name="seo_title" maxlength="70" required value="{{ old('seo_title', $content['seo_title']) }}"></label>
                <label class="field field-wide"><span>Headline utama</span><input class="form-control" name="landing_headline" maxlength="220" required value="{{ old('landing_headline', $content['headline']) }}"></label>
                <label class="field field-wide"><span>Subheadline</span><textarea class="form-control" name="landing_subheadline" rows="3" maxlength="3000" required>{{ old('landing_subheadline', $content['subheadline']) }}</textarea></label>
                <label class="field field-wide"><span>Manfaat <small>(satu manfaat per baris, maks. 8)</small></span><textarea class="form-control" name="benefits_text" rows="5">{{ old('benefits_text', collect($content['benefits'])->implode("\n")) }}</textarea></label>
                <label class="field field-wide"><span>Tahapan <small>(Judul | Penjelasan, maks. 8)</small></span><textarea class="form-control" name="process_text" rows="7">{{ old('process_text', collect($content['process'])->map(fn($item) => $item['title'].' | '.($item['description'] ?? ''))->implode("\n")) }}</textarea></label>
                <label class="field field-wide"><span>FAQ <small>(Pertanyaan | Jawaban, maks. 12)</small></span><textarea class="form-control" name="faqs_text" rows="9">{{ old('faqs_text', collect($content['faqs'])->map(fn($item) => $item['question'].' | '.$item['answer'])->implode("\n")) }}</textarea></label>
                <label class="field field-wide"><span>Deskripsi SEO <small>(maks. 160)</small></span><textarea class="form-control" name="seo_description" rows="3" maxlength="160" required>{{ old('seo_description', $content['seo_description']) }}</textarea></label>
            </div>
            <div class="service-landing-editor-actions"><small>Perubahan langsung berlaku setelah disimpan. Tidak perlu redeploy untuk mengubah copy.</small><button class="btn btn-primary" type="submit">Simpan landing {{ $service->short_name }}</button></div>
        </form>
    </details>
@endforeach
</section>
@endsection

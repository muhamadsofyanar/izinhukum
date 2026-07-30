@extends('layouts.admin')
@section('title', 'FAQ Otomatis')
@section('heading', 'FAQ Otomatis Terkontrol')
@section('content')
@include('admin.whatsapp._nav')
<div class="wa-grid">
    <section class="wa-card wa-span-8">
        <h2>Aturan FAQ</h2>
        <p class="wa-tabs-note">Aktifkan feature flag FAQ setelah jawaban diperiksa. Pertanyaan hukum kompleks tetap harus dialihkan kepada admin.</p>
        @forelse($rules as $faq)
            <article class="wa-faq-card">
                <form method="post" action="{{ route('admin.whatsapp.faq.update',$faq) }}" class="wa-form-grid">
                    @csrf @method('put')
                    <div><label>Nama aturan</label><input class="form-control" name="name" value="{{ $faq->name }}" required></div>
                    <div><label>Kata kunci</label><input class="form-control" name="keyword" value="{{ $faq->keyword }}" required></div>
                    <div><label>Cara cocok</label><select class="form-select" name="match_type"><option value="exact" @selected($faq->match_type==='exact')>Persis</option><option value="contains" @selected($faq->match_type==='contains')>Mengandung</option><option value="regex" @selected($faq->match_type==='regex')>Regex</option></select></div>
                    <div><label>Prioritas</label><input class="form-control" type="number" name="priority" value="{{ $faq->priority }}" min="1" max="9999"></div>
                    <div class="full"><label>Template opsional</label><select class="form-select" name="template_id"><option value="">Jawaban teks di bawah</option>@foreach($templates as $template)<option value="{{ $template->id }}" @selected($faq->template_id===$template->id)>{{ $template->name }}</option>@endforeach</select></div>
                    <div class="full"><label>Jawaban</label><textarea class="form-control" name="answer" rows="4">{{ $faq->answer }}</textarea></div>
                    <div><label class="wa-confirm-box"><input type="checkbox" name="is_active" value="1" @checked($faq->is_active)> Aktif</label></div>
                    <div><label class="wa-confirm-box"><input type="checkbox" name="handoff_after_reply" value="1" @checked($faq->handoff_after_reply)> Alihkan ke admin setelah jawaban.</label></div>
                    <div class="full"><button class="btn btn-outline-primary">Simpan</button></div>
                </form>
                <form method="post" action="{{ route('admin.whatsapp.faq.destroy',$faq) }}" class="mt-2" onsubmit="return confirm('Hapus aturan FAQ?')">
                    @csrf @method('delete')
                    <button class="btn btn-outline-danger">Hapus</button>
                </form>
            </article>
        @empty<p class="wa-muted">Belum ada aturan FAQ.</p>@endforelse
        {{ $rules->links() }}
    </section>
    <aside class="wa-card wa-span-4">
        <h2>Tambah aturan FAQ</h2>
        <form method="post" action="{{ route('admin.whatsapp.faq.store') }}" class="wa-form-grid">
            @csrf
            <div class="full"><label>Nama aturan</label><input class="form-control" name="name" required placeholder="Biaya pendirian PT"></div>
            <div class="full"><label>Kata kunci</label><input class="form-control" name="keyword" required placeholder="biaya pendirian pt"></div>
            <div><label>Cara cocok</label><select class="form-select" name="match_type"><option value="contains">Mengandung</option><option value="exact">Persis</option><option value="regex">Regex</option></select></div>
            <div><label>Prioritas</label><input class="form-control" type="number" name="priority" value="100" min="1"></div>
            <div class="full"><label>Template opsional</label><select class="form-select" name="template_id"><option value="">Tanpa template</option>@foreach($templates as $template)<option value="{{ $template->id }}">{{ $template->name }}</option>@endforeach</select></div>
            <div class="full"><label>Jawaban</label><textarea class="form-control" name="answer" rows="6"></textarea></div>
            <div class="full"><label class="wa-confirm-box"><input type="checkbox" name="is_active" value="1"> Aktifkan setelah disimpan.</label></div>
            <div class="full"><label class="wa-confirm-box"><input type="checkbox" name="handoff_after_reply" value="1"> Alihkan ke admin setelah jawaban.</label></div>
            <div class="full"><button class="btn btn-primary">Simpan aturan</button></div>
        </form>
    </aside>
</div>
@endsection

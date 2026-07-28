@extends('layouts.admin')

@php($editing = $article->exists)
@section('title', $editing ? 'Edit Artikel' : 'Tulis Artikel')
@section('heading', $editing ? 'Edit Artikel' : 'Tulis Artikel')

@section('content')
<form class="portal-form" action="{{ $editing ? route('admin.articles.update', $article) : route('admin.articles.store') }}" method="post">
    @csrf @if($editing) @method('PUT') @endif
    <section class="admin-panel portal-section">
        <div class="p-4">
            <div class="row g-3">
                <div class="col-12"><label class="form-label">Judul *</label><input class="form-control" name="title" value="{{ old('title', $article->title) }}" maxlength="220" required></div>
                <div class="col-12"><label class="form-label">Ringkasan *</label><textarea class="form-control" name="excerpt" rows="3" maxlength="1000" required>{{ old('excerpt', $article->excerpt) }}</textarea></div>
                <div class="col-12"><label class="form-label">Isi artikel *</label><textarea class="form-control article-editor" name="body" rows="18" maxlength="100000" required>{{ old('body', $article->body) }}</textarea><small class="form-text">Pisahkan paragraf dengan satu baris kosong. Teks dirender aman tanpa kode HTML.</small></div>
                <div class="col-12"><label class="form-label">URL gambar utama</label><input class="form-control" name="featured_image" type="url" value="{{ old('featured_image', $article->featured_image) }}" placeholder="https://..."></div>
                <div class="col-md-4"><label class="form-label">Status</label><select class="form-select" name="status"><option value="draft" @selected(old('status', $article->status ?: 'draft') === 'draft')>Draf</option><option value="published" @selected(old('status', $article->status) === 'published')>Terbit</option></select></div>
                <div class="col-md-4"><label class="form-label">Tanggal terbit</label><input class="form-control" name="published_at" type="datetime-local" value="{{ old('published_at', $article->published_at?->format('Y-m-d\TH:i')) }}"></div>
                <div class="col-md-4"><label class="form-label">Judul SEO</label><input class="form-control" name="seo_title" value="{{ old('seo_title', $article->seo_title) }}" maxlength="220"></div>
                <div class="col-12"><label class="form-label">Meta description</label><textarea class="form-control" name="meta_description" rows="2" maxlength="320">{{ old('meta_description', $article->meta_description) }}</textarea></div>
            </div>
        </div>
    </section>
    <div class="d-flex flex-wrap gap-2 mt-3">
        <button class="btn btn-primary" type="submit">{{ $editing ? 'Simpan perubahan' : 'Buat artikel' }}</button>
        <a class="btn btn-outline-secondary" href="{{ route('admin.articles.index') }}">Kembali</a>
    </div>
</form>
@if($editing)
<form class="mt-4" action="{{ route('admin.articles.destroy', $article) }}" method="post" onsubmit="return confirm('Pindahkan artikel ini ke arsip?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" type="submit">Arsipkan artikel</button></form>
@endif
@endsection

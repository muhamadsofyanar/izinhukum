@extends('layouts.app')

@section('title', $article->seo_title ?: $article->title)
@section('meta_description', $article->meta_description ?: $article->excerpt)

@section('content')
<article>
    <header class="article-hero">
        <div class="container">
            <nav class="breadcrumb-nav" aria-label="Breadcrumb"><a href="{{ route('home') }}">Beranda</a><span>/</span><a href="{{ route('articles.index') }}">Artikel</a><span>/</span><span>{{ \Illuminate\Support\Str::limit($article->title, 45) }}</span></nav>
            <span class="eyebrow">Panduan IzinHukum</span>
            <h1>{{ $article->title }}</h1>
            <p>{{ $article->excerpt }}</p>
            <small>Diterbitkan {{ $article->published_at->translatedFormat('d F Y') }}</small>
        </div>
    </header>
    @if($article->featured_image)<div class="container article-cover"><img src="{{ $article->featured_image }}" alt="{{ $article->title }}"></div>@endif
    <div class="container article-layout">
        <div class="article-content">
            @foreach(preg_split('/\R{2,}/', trim($article->body)) as $paragraph)
                <p>{!! nl2br(e($paragraph)) !!}</p>
            @endforeach
        </div>
        <aside class="article-side">
            <strong>Perlu bantuan langsung?</strong>
            <p>Konsultasikan dokumen dan ruang lingkup usaha Anda dengan tim IzinHukum.</p>
            <a class="btn btn-primary w-100" href="{{ route('proposal.create') }}">Konsultasi gratis</a>
        </aside>
    </div>
</article>
@if($related->isNotEmpty())
<section class="section section-soft">
    <div class="container"><div class="section-heading"><span class="eyebrow">Artikel lainnya</span><h2>Lanjutkan membaca</h2></div><div class="row g-4">@foreach($related as $item)<div class="col-md-4"><a class="related-card" href="{{ route('articles.show', $item) }}"><small>{{ $item->published_at->format('d M Y') }}</small><strong>{{ $item->title }}</strong><span>Baca →</span></a></div>@endforeach</div></div>
</section>
@endif
@endsection

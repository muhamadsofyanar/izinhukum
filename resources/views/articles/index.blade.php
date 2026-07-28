@extends('layouts.app')

@section('title', 'Artikel Legalitas dan Perizinan Usaha')
@section('meta_description', 'Panduan praktis tentang pendirian badan usaha, OSS, KBLI, legalitas, dan perizinan dari IzinHukum.')

@section('content')
<section class="page-hero page-hero-compact">
    <div class="container">
        <span class="eyebrow">Pusat pengetahuan</span>
        <h1>Artikel legalitas dan perizinan usaha</h1>
        <p>Penjelasan praktis untuk membantu Anda memahami dokumen, proses, dan pilihan badan usaha sebelum berkonsultasi.</p>
    </div>
</section>
<section class="section">
    <div class="container">
        <div class="row g-4">
            @forelse($articles as $article)
                <div class="col-md-6 col-lg-4">
                    <article class="article-card">
                        @if($article->featured_image)<img src="{{ $article->featured_image }}" alt="" loading="lazy">@else<div class="article-placeholder"><span>IH</span></div>@endif
                        <div class="article-card-body">
                            <small>{{ $article->published_at->format('d M Y') }}</small>
                            <h2><a href="{{ route('articles.show', $article) }}">{{ $article->title }}</a></h2>
                            <p>{{ $article->excerpt }}</p>
                            <a class="text-link" href="{{ route('articles.show', $article) }}">Baca selengkapnya →</a>
                        </div>
                    </article>
                </div>
            @empty
                <div class="col-12"><div class="empty-state"><h2>Artikel sedang disiapkan</h2><p>Silakan kembali lagi atau konsultasikan kebutuhan Anda langsung dengan tim kami.</p></div></div>
            @endforelse
        </div>
        <div class="mt-5">{{ $articles->links() }}</div>
    </div>
</section>
@endsection

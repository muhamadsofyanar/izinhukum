@extends('layouts.admin')

@section('title', 'Artikel')
@section('heading', 'Artikel')
@section('header_action')<a class="btn btn-primary" href="{{ route('admin.articles.create') }}">Tulis artikel</a>@endsection

@section('content')
<section class="admin-panel">
    <div class="table-responsive">
        <table class="table admin-table">
            <thead><tr><th>Judul</th><th>Status</th><th>Penulis</th><th>Terbit</th><th></th></tr></thead>
            <tbody>
            @forelse($articles as $article)
                <tr>
                    <td><strong>{{ $article->title }}</strong><small>/artikel/{{ $article->slug }}</small></td>
                    <td><span class="status status-{{ $article->status }}">{{ ucfirst($article->status) }}</span></td>
                    <td>{{ $article->author?->name ?? '—' }}</td>
                    <td>{{ $article->published_at?->format('d/m/Y H:i') ?? '—' }}</td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.articles.edit', $article) }}">Edit</a></td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center py-5">Belum ada artikel.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="p-3">{{ $articles->links() }}</div>
</section>
@endsection

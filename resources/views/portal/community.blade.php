@extends('layouts.admin')
@section('title','Community')
@section('heading','Community')
@section('content')
<div class="community-layout">
<section class="admin-panel community-compose"><div class="admin-panel-head"><h2>Buat diskusi</h2></div>
<form method="post" enctype="multipart/form-data" action="{{ route(($currentUser->isAdmin()?'admin':'partner').'.community.store') }}" class="stack-form">@csrf
<input class="form-control" name="title" placeholder="Judul (opsional)">
<textarea class="form-control" name="body" rows="5" placeholder="Bagikan pertanyaan, pengalaman, atau informasi..." required></textarea>
<input class="form-control" type="file" name="attachment" accept=".jpg,.jpeg,.png,.webp,.pdf">
<button class="btn btn-primary">Posting</button></form></section>
<section class="community-feed">@forelse($posts as $post)
<article class="admin-panel community-post"><header><span class="avatar">{{ strtoupper(substr($post->user->name,0,2)) }}</span><div><strong>{{ $post->user->name }}</strong><small>{{ $post->created_at->diffForHumans() }}</small></div></header>
@if($post->title)<h2>{{ $post->title }}</h2>@endif<p>{{ $post->body }}</p>
@if($post->attachment_path)<a href="{{ asset('storage/'.$post->attachment_path) }}" target="_blank">Buka lampiran ↗</a>@endif
<div class="community-comments">@foreach($post->comments as $comment)<p><strong>{{ $comment->user->name }}</strong> {{ $comment->body }}</p>@endforeach</div>
<form class="inline-admin-form" method="post" action="{{ route(($currentUser->isAdmin()?'admin':'partner').'.community.comment',$post) }}">@csrf<input class="form-control" name="body" placeholder="Tulis komentar..." required><button class="btn btn-secondary">Kirim</button></form>
</article>@empty<div class="empty-state">Belum ada diskusi.</div>@endforelse {{ $posts->links() }}</section>
</div>
@endsection

@extends('layouts.admin')
@section('title','Inbox')
@section('heading','Inbox')
@section('content')
<div class="inbox-layout">
<section class="admin-panel"><div class="admin-panel-head"><h2>Pesan baru</h2></div><form class="stack-form inbox-compose" method="post" action="{{ route(($currentUser->isAdmin()?'admin':'partner').'.inbox.store') }}">@csrf
<select class="form-select" name="recipient_id" required><option value="">Pilih penerima</option>@foreach($recipients as $recipient)<option value="{{ $recipient->id }}">{{ $recipient->name }} — {{ $recipient->email }}</option>@endforeach</select>
<input class="form-control" name="subject" placeholder="Subjek" required><textarea class="form-control" name="body" rows="7" placeholder="Tulis pesan..." required></textarea><button class="btn btn-primary">Kirim pesan</button></form></section>
<section class="admin-panel"><div class="admin-panel-head"><h2>Riwayat pesan</h2></div><div class="message-list">@forelse($messages as $message)
<article class="{{ $message->sender_id===$currentUser->id?'message-sent':'message-received' }}"><small>{{ $message->sender_id===$currentUser->id?'Kepada '.$message->recipient->name:'Dari '.$message->sender->name }} · {{ $message->created_at->diffForHumans() }}</small><h3>{{ $message->subject }}</h3><p>{{ $message->body }}</p></article>
@empty<div class="empty-state">Belum ada pesan.</div>@endforelse</div>{{ $messages->links() }}</section>
</div>
@endsection

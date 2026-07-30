@extends('layouts.admin')
@section('title', 'Percakapan WhatsApp')
@section('heading', 'Pusat WhatsApp')
@section('header_action')
    <a class="btn btn-primary" href="{{ route('admin.whatsapp.contacts.index') }}">Tambah kontak</a>
@endsection
@section('content')
@include('admin.whatsapp._nav')

<section class="wa-inbox-shell">
    @include('admin.whatsapp._inbox_sidebar')
    <div class="wa-inbox-welcome">
        <span class="wa-welcome-mark" aria-hidden="true">WA</span>
        <h2>Pilih percakapan</h2>
        <p>Buka salah satu chat di sebelah kiri untuk membaca dan membalas pesan.</p>
        <div class="wa-welcome-actions">
            <a class="btn btn-primary" href="{{ route('admin.whatsapp.contacts.index') }}">Mulai dari kontak</a>
            <a class="btn btn-outline-primary" href="{{ route('admin.whatsapp.groups.index') }}">Buka grup</a>
        </div>
    </div>
</section>
@endsection

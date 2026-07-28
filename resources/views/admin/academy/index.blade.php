@extends('layouts.admin')
@section('title', 'LMS Akademi')
@section('heading', 'LMS Akademi Mitra')
@section('header_action')
<div class="d-flex gap-2"><a class="btn btn-outline-primary" href="{{ route('admin.academy.report') }}">Laporan</a><a class="btn btn-primary" href="{{ route('admin.academy.create') }}">Buat kelas</a></div>
@endsection
@section('content')
<div class="admin-stats">
    <article><span>Total kelas</span><strong>{{ $courses->total() }}</strong></article>
    <article><span>Kelas terbit</span><strong>{{ $courses->where('status', 'published')->count() }}</strong></article>
    <article><span>Kategori</span><strong>{{ $categories->count() }}</strong></article>
    <article><span>Mitra aktif</span><strong>{{ $partners->count() }}</strong></article>
</div>
<section class="admin-panel mb-4">
    <div class="admin-panel-head"><h2>Kategori kelas</h2></div>
    <form method="post" action="{{ route('admin.academy.categories.store') }}" class="inline-admin-form">@csrf
        <input class="form-control" name="name" placeholder="Contoh: Onboarding Mitra" required>
        <button class="btn btn-secondary">Tambah kategori</button>
    </form>
</section>
<section class="admin-panel">
    <div class="admin-panel-head"><h2>Semua kelas</h2><small>Tidak ada batas jumlah kelas.</small></div>
    <div class="table-responsive"><table class="table admin-table">
        <thead><tr><th>Kelas</th><th>Status</th><th>Materi</th><th>Peserta</th><th>Aksi</th></tr></thead>
        <tbody>@forelse($courses as $course)
        <tr>
            <td><strong>{{ $course->title }}</strong><small>{{ $course->category?->name ?: 'Tanpa kategori' }} · {{ ucfirst($course->level) }}</small></td>
            <td><span class="status status-{{ $course->status }}">{{ ucfirst($course->status) }}</span></td>
            <td>{{ $course->sections_count }} bab</td><td>{{ $course->enrollments_count }} mitra</td>
            <td><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.academy.edit', $course) }}">Kelola</a></td>
        </tr>@empty<tr><td colspan="5" class="text-center py-5">Belum ada kelas. Buat kelas pertama untuk onboarding mitra.</td></tr>@endforelse</tbody>
    </table></div>{{ $courses->links() }}
</section>
@endsection

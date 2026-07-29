@extends('layouts.admin')
@section('title','Kelas Saya')
@section('heading','Kelas Saya')
@section('content')
<div class="course-grid">
@forelse($enrollments as $enrollment)
<article class="course-card"><small>{{ $enrollment->course->category?->name ?: 'Akademi Mitra' }}</small><h2>{{ $enrollment->course->title }}</h2><p>{{ $enrollment->course->summary }}</p>
<div class="progress-track"><span style="width:{{ $enrollment->progress_percent }}%"></span></div><div class="course-meta"><span>{{ $enrollment->progress_percent }}% selesai</span><span>{{ ucfirst($enrollment->status) }}</span></div>
@if($enrollment->course->trashed())<span class="status status-archived">Kelas diarsipkan</span>@else<a class="btn btn-primary" href="{{ route('partner.learning.show',$enrollment->course) }}">{{ $enrollment->status==='completed' ? 'Lihat kembali' : 'Lanjut belajar' }}</a>@endif</article>
@empty<div class="empty-state"><h2>Belum ada kelas</h2><p>Kelas yang ditugaskan admin akan tampil di sini.</p></div>@endforelse
</div>{{ $enrollments->links() }}
@endsection

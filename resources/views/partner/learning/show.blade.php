@extends('layouts.admin')
@section('title',$course->title)
@section('heading',$course->title)
@section('content')
<section class="admin-panel course-intro"><div><span class="status status-{{ $enrollment->status }}">{{ $enrollment->progress_percent }}% selesai</span><p>{{ $course->summary }}</p></div>
@if($enrollment->certificate_number)<div class="certificate-box"><small>Sertifikat kelulusan</small><strong>{{ $enrollment->certificate_number }}</strong><button class="btn btn-outline-primary" onclick="window.print()">Cetak sertifikat</button></div>@endif</section>
@foreach($course->sections as $section)<section class="admin-panel mb-4"><div class="admin-panel-head"><h2>{{ $section->title }}</h2></div>
@foreach($section->lessons as $lesson)<article class="learning-lesson @if($completed->contains($lesson->id)) completed @endif"><div><small>{{ strtoupper($lesson->type) }} · {{ $lesson->duration_minutes }} menit</small><h3>{{ $lesson->title }}</h3>
@if($lesson->content)<div class="lesson-content">{!! nl2br(e($lesson->content)) !!}</div>@endif
@if($lesson->resource_url)<a href="{{ $lesson->resource_url }}" target="_blank" rel="noopener">Buka materi ↗</a>@endif</div>
@if($completed->contains($lesson->id))<span class="completion-mark">Selesai ✓</span>@else<form method="post" action="{{ route('partner.learning.complete',[$course,$lesson]) }}">@csrf<button class="btn btn-primary">Tandai selesai</button></form>@endif</article>@endforeach</section>@endforeach
@endsection

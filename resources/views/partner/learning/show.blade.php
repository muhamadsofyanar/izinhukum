@extends('layouts.admin')
@section('title',$course->title)
@section('heading',$course->title)
@section('content')
<section class="admin-panel course-intro"><div><span class="status status-{{ $enrollment->status }}">{{ $enrollment->progress_percent }}% selesai</span><p>{{ $course->summary }}</p></div>
@if($enrollment->certificate_number)<div class="certificate-box"><small>Sertifikat kelulusan</small><strong>{{ $enrollment->certificate_number }}</strong><a class="btn btn-outline-primary" href="{{ route('partner.learning.certificate', $enrollment) }}" target="_blank">Buka sertifikat</a></div>@endif</section>
@foreach($course->sections as $section)<section class="admin-panel mb-4"><div class="admin-panel-head"><h2>{{ $section->title }}</h2></div>
@foreach($section->lessons as $lesson)<article class="learning-lesson @if($completed->contains($lesson->id)) completed @endif"><div><small>{{ strtoupper($lesson->type) }} · {{ $lesson->duration_minutes }} menit</small><h3>{{ $lesson->title }}</h3>
@if($lesson->content)<div class="lesson-content">{!! nl2br(e($lesson->content)) !!}</div>@endif
@if($lesson->type === 'video' && $videoEmbeds->get($lesson->id))
    <div class="video-material"><iframe title="{{ $lesson->title }}" src="{{ $videoEmbeds->get($lesson->id) }}" loading="lazy" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe></div>
@elseif($lesson->resource_url)
    <a href="{{ $lesson->resource_url }}" target="_blank" rel="noopener noreferrer">Buka materi pendukung ↗</a>
@endif</div>
@if($lesson->file_path)<div class="pdf-material"><a class="btn btn-outline-primary" href="{{ route('partner.learning.material', [$course, $lesson]) }}" target="_blank">Buka / unduh {{ $lesson->original_filename ?: 'PDF' }}</a><iframe title="{{ $lesson->title }}" src="{{ route('partner.learning.material', [$course, $lesson]) }}"></iframe></div>@endif
@if($completed->contains($lesson->id))<span class="completion-mark">Selesai ✓</span>@else<form method="post" action="{{ route('partner.learning.complete',[$course,$lesson]) }}">@csrf<button class="btn btn-primary">Tandai selesai</button></form>@endif</article>@endforeach</section>@endforeach
@endsection

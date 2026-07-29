@extends('layouts.admin')

@section('title', $course->title)
@section('heading', $course->title)

@section('header_action')
<a class="btn btn-outline-secondary" href="{{ route('partner.learning.index') }}">Kembali ke kelas saya</a>
@endsection

@section('content')
<section class="learning-overview">
    <div class="learning-overview-copy">
        <span class="learning-eyebrow">{{ ucfirst($course->level) }} · {{ $course->estimated_minutes }} menit</span>
        <p>{{ $course->summary }}</p>
        <div class="learning-progress-row">
            <div class="progress-track" aria-label="Progres kelas">
                <span style="width: {{ $enrollment->progress_percent }}%"></span>
            </div>
            <strong>{{ $enrollment->progress_percent }}%</strong>
        </div>
    </div>
    @if($enrollment->certificate_number)
        <div class="certificate-box">
            <small>Kelas selesai</small>
            <strong>{{ $enrollment->certificate_number }}</strong>
            <a class="btn btn-outline-primary" href="{{ route('partner.learning.certificate', $enrollment) }}" target="_blank">Buka sertifikat</a>
        </div>
    @endif
</section>

@if($activeLesson)
<div class="learning-reader">
    <aside class="learning-outline admin-panel">
        <div class="learning-outline-head">
            <span>Isi kelas</span>
            <strong>{{ $completed->count() }}/{{ $lessons->count() }} selesai</strong>
        </div>
        <nav aria-label="Daftar materi kelas">
            @foreach($course->sections as $section)
                <section class="learning-section">
                    <h2>{{ $section->title }}</h2>
                    @foreach($section->lessons as $lesson)
                        @php
                            $isActive = $activeLesson->id === $lesson->id;
                            $isCompleted = $completed->contains($lesson->id);
                        @endphp
                        <a class="learning-outline-item {{ $isActive ? 'active' : '' }} {{ $isCompleted ? 'completed' : '' }}"
                           href="{{ route('partner.learning.show', ['course' => $course, 'materi' => $lesson->id]) }}"
                           @if($isActive) aria-current="page" @endif>
                            <span class="learning-step">{{ $isCompleted ? '✓' : $loop->iteration }}</span>
                            <span>
                                <strong>{{ $lesson->title }}</strong>
                                <small>{{ strtoupper($lesson->type) }} · {{ $lesson->duration_minutes }} menit</small>
                            </span>
                        </a>
                    @endforeach
                </section>
            @endforeach
        </nav>
    </aside>

    <main class="learning-stage admin-panel">
        <header class="learning-stage-head">
            <div>
                <span>{{ strtoupper($activeLesson->type) }} · {{ $activeLesson->duration_minutes }} menit</span>
                <h2>{{ $activeLesson->title }}</h2>
            </div>
            @if($completed->contains($activeLesson->id))
                <span class="status status-paid">Selesai ✓</span>
            @else
                <span class="status status-pending">Belum selesai</span>
            @endif
        </header>

        <div class="learning-stage-body">
            @if($activeVideoEmbed)
                <div class="video-material">
                    <iframe title="{{ $activeLesson->title }}"
                            src="{{ $activeVideoEmbed }}"
                            loading="lazy"
                            allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture; web-share"
                            allowfullscreen></iframe>
                </div>
            @endif

            @if($activeLesson->content)
                <div class="lesson-content">{!! nl2br(e($activeLesson->content)) !!}</div>
            @endif

            @if($activeLesson->file_path)
                <div class="pdf-material">
                    <div class="pdf-material-head">
                        <div>
                            <strong>{{ $activeLesson->original_filename ?: 'Materi PDF' }}</strong>
                            <small>Dokumen hanya tersedia untuk peserta kelas.</small>
                        </div>
                        <a class="btn btn-outline-primary"
                           href="{{ route('partner.learning.material', [$course, $activeLesson]) }}"
                           target="_blank">Buka di tab baru</a>
                    </div>
                    <iframe title="{{ $activeLesson->title }}"
                            src="{{ route('partner.learning.material', [$course, $activeLesson]) }}"></iframe>
                </div>
            @elseif($activeLesson->resource_url && ! $activeVideoEmbed)
                <div class="learning-resource">
                    <span>Materi pendukung tersedia melalui tautan eksternal.</span>
                    <a class="btn btn-outline-primary" href="{{ $activeLesson->resource_url }}" target="_blank" rel="noopener noreferrer">Buka materi ↗</a>
                </div>
            @endif

            @if(! $activeVideoEmbed && ! $activeLesson->content && ! $activeLesson->file_path && ! $activeLesson->resource_url)
                <div class="empty-state learning-empty">
                    <h3>Materi belum diisi</h3>
                    <p>Hubungi admin jika isi materi seharusnya sudah tersedia.</p>
                </div>
            @endif
        </div>

        <footer class="learning-stage-footer">
            <div>
                @if($previousLesson)
                    <a class="btn btn-outline-secondary"
                       href="{{ route('partner.learning.show', ['course' => $course, 'materi' => $previousLesson->id]) }}">← Sebelumnya</a>
                @endif
            </div>
            <div class="learning-stage-actions">
                @if(! $completed->contains($activeLesson->id))
                    <form method="post" action="{{ route('partner.learning.complete', [$course, $activeLesson]) }}">
                        @csrf
                        <button class="btn btn-primary">Tandai selesai</button>
                    </form>
                @endif
                @if($nextLesson)
                    <a class="btn btn-outline-primary"
                       href="{{ route('partner.learning.show', ['course' => $course, 'materi' => $nextLesson->id]) }}">Berikutnya →</a>
                @endif
            </div>
        </footer>
    </main>
</div>
@else
    <div class="empty-state admin-panel">
        <h2>Materi belum tersedia</h2>
        <p>Admin belum menambahkan materi pada kelas ini.</p>
    </div>
@endif
@endsection

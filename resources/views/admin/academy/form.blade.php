@extends('layouts.admin')
@section('title', $course->exists ? 'Kelola Kelas' : 'Buat Kelas')
@section('heading', $course->exists ? 'Kelola Kelas' : 'Buat Kelas Baru')
@section('content')
<form class="admin-panel mb-4" method="post" action="{{ $course->exists ? route('admin.academy.update', $course) : route('admin.academy.store') }}">
    @csrf @if($course->exists) @method('put') @endif
    <div class="form-grid">
        <label class="field field-wide"><span>Judul kelas</span><input class="form-control" name="title" value="{{ old('title', $course->title) }}" required></label>
        <label class="field"><span>Kategori</span><select class="form-select" name="category_id"><option value="">Tanpa kategori</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(old('category_id', $course->category_id)==$category->id)>{{ $category->name }}</option>@endforeach</select></label>
        <label class="field"><span>Level</span><select class="form-select" name="level">@foreach(['dasar','menengah','lanjutan'] as $level)<option @selected(old('level', $course->level ?: 'dasar')===$level)>{{ $level }}</option>@endforeach</select></label>
        <label class="field"><span>Status</span><select class="form-select" name="status">@foreach(['draft','published','hidden','archived'] as $status)<option @selected(old('status', $course->status ?: 'draft')===$status)>{{ $status }}</option>@endforeach</select></label>
        <label class="field"><span>Nilai minimum</span><input class="form-control" type="number" min="0" max="100" name="passing_score" value="{{ old('passing_score', $course->passing_score ?? 70) }}" required></label>
        <label class="field"><span>Estimasi menit</span><input class="form-control" type="number" min="0" name="estimated_minutes" value="{{ old('estimated_minutes', $course->estimated_minutes ?? 0) }}" required></label>
        <label class="field field-wide"><span>Ringkasan</span><textarea class="form-control" name="summary" rows="3" required>{{ old('summary', $course->summary) }}</textarea></label>
        <label class="field field-wide"><span>Deskripsi lengkap</span><textarea class="form-control" name="description" rows="6">{{ old('description', $course->description) }}</textarea></label>
        <label class="check-field"><input type="hidden" name="is_mandatory" value="0"><input type="checkbox" name="is_mandatory" value="1" @checked(old('is_mandatory', $course->is_mandatory))> Kelas wajib</label>
        <label class="check-field"><input type="hidden" name="auto_enroll" value="0"><input type="checkbox" name="auto_enroll" value="1" @checked(old('auto_enroll', $course->auto_enroll))> Otomatis daftarkan semua mitra</label>
    </div>
    <button class="btn btn-primary mt-3">{{ $course->exists ? 'Simpan perubahan' : 'Buat kelas' }}</button>
</form>
@if($course->exists)
<div class="academy-builder">
    <section class="admin-panel">
        <div class="admin-panel-head"><h2>Bab & materi</h2></div>
        @foreach($course->sections as $section)
        <article class="course-section-card">
            <h3>{{ $section->title }}</h3>
            @foreach($section->lessons as $lesson)
            <div class="lesson-row"><span><strong>{{ $lesson->title }}</strong><small>{{ strtoupper($lesson->type) }} · {{ $lesson->duration_minutes }} menit</small></span>
                <form method="post" action="{{ route('admin.academy.lessons.destroy', $lesson) }}">@csrf @method('delete')<button class="btn btn-sm btn-outline-danger">Hapus</button></form>
            </div>@endforeach
            <details><summary>+ Tambah materi</summary>
                <form method="post" enctype="multipart/form-data" action="{{ route('admin.academy.lessons.store', $section) }}" class="stack-form">@csrf
                    <input class="form-control" name="title" placeholder="Judul materi" required>
                    <select class="form-select" name="type">@foreach(['text','video','pdf','link','assignment','quiz'] as $type)<option>{{ $type }}</option>@endforeach</select>
                    <input class="form-control" type="url" name="resource_url" placeholder="URL video, PDF, atau materi">
                    <label class="field"><span>Atau unggah PDF (maks. 25 MB)</span><input class="form-control" type="file" name="material_file" accept="application/pdf"></label>
                    <input class="form-control" type="number" name="duration_minutes" value="0" min="0" placeholder="Durasi menit">
                    <textarea class="form-control" name="content" placeholder="Isi materi/instruksi"></textarea>
                    <button class="btn btn-secondary">Tambah materi</button>
                </form>
            </details>
        </article>@endforeach
        <form method="post" action="{{ route('admin.academy.sections.store', $course) }}" class="inline-admin-form">@csrf
            <input class="form-control" name="title" placeholder="Nama bab baru" required><button class="btn btn-secondary">Tambah bab</button>
        </form>
    </section>
    <section class="admin-panel">
        <div class="admin-panel-head"><h2>Daftarkan peserta</h2></div>
        <form method="post" action="{{ route('admin.academy.assign', $course) }}" class="stack-form">@csrf
            <label><input type="radio" name="assignment_scope" value="all" checked> Semua mitra aktif</label>
            <label><input type="radio" name="assignment_scope" value="selected"> Mitra terpilih</label>
            <select class="form-select" name="partner_ids[]" multiple size="10">@foreach(\App\Models\User::where('role','partner')->orderBy('name')->get() as $partner)<option value="{{ $partner->id }}">{{ $partner->name }} — {{ $partner->partner_code }}</option>@endforeach</select>
            <button class="btn btn-primary">Daftarkan peserta</button>
        </form>
    </section>
</div>
@endif
@endsection

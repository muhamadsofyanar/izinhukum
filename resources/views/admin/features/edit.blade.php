@extends('layouts.admin')

@section('title', 'Pengaturan Fitur')
@section('heading', 'Pengaturan Fitur Aplikasi')

@section('content')
<section class="admin-panel">
    <div class="admin-panel-head">
        <div>
            <h2>Feature switch</h2>
            <p class="mb-0 text-muted">Aktifkan atau nonaktifkan fitur operasional tanpa mengubah kode dan tanpa redeploy.</p>
        </div>
    </div>
    <form action="{{ route('admin.features.update') }}" method="post" class="p-4">
        @csrf
        @method('PUT')
        <div class="feature-switch-grid">
            @foreach($features as $feature)
                <label class="feature-switch-card">
                    <span>
                        <strong>{{ $feature['label'] }}</strong>
                        <small>{{ $feature['description'] }}</small>
                    </span>
                    <span class="form-check form-switch m-0">
                        <input type="hidden" name="features[{{ $feature['key'] }}]" value="0">
                        <input class="form-check-input" type="checkbox" name="features[{{ $feature['key'] }}]" value="1" @checked($feature['enabled'])>
                    </span>
                </label>
            @endforeach
        </div>
        <div class="admin-note mt-4">
            Menonaktifkan fitur membuat rute publik atau portal terkait mengembalikan halaman 404. Data lama tetap tersimpan.
        </div>
        <button class="btn btn-primary mt-4" type="submit">Simpan pengaturan fitur</button>
    </form>
</section>
@endsection

@extends('layouts.app')

@section('title', 'Generator Nama Badan Usaha dan Yayasan')
@section('meta_description', 'Buat alternatif nama PT, PT PMA, Perseroan Perorangan, CV, Firma, Persekutuan Perdata, Yayasan, Perkumpulan, dan Koperasi dengan pemeriksaan format awal.')

@section('content')
<section class="page-hero page-hero-compact">
    <div class="container">
        <span class="eyebrow">Alat gratis IzinHukum</span>
        <h1>Generator nama badan</h1>
        <p>Dapatkan alternatif yang mudah dibaca dan mengikuti pagar format awal. Ketersediaan serta persetujuan nama tetap harus diperiksa melalui sistem AHU.</p>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="row g-4 align-items-start">
            <div class="col-lg-4">
                <form class="form-card tool-form sticky-lg-top" action="{{ route('tools.name-generator') }}" method="get">
                    <label class="field">
                        <span>Jenis badan</span>
                        <select class="form-select" name="jenis">
                            @foreach($entityTypes as $value => $label)
                                <option value="{{ $value }}" @selected($entityType === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="field">
                        <span>Bidang utama</span>
                        <select class="form-select" name="sektor">
                            @foreach($sectors as $value => $label)
                                <option value="{{ $value }}" @selected($sector === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="field">
                        <span>Satu sampai tiga kata utama</span>
                        <input class="form-control" name="kata" value="{{ $keyword }}" maxlength="40" required placeholder="Contoh: Lentera, Mekar Digital">
                        <small>Jangan tulis PT, CV, Firma, atau Yayasan; awalan ditambahkan otomatis.</small>
                    </label>
                    <button class="btn btn-primary" type="submit">Buat alternatif nama</button>
                </form>

                <aside class="legal-notice mt-3">
                    <strong>Pemeriksaan awal, bukan persetujuan AHU</strong>
                    <p>Generator tidak mengakses daftar nama AHU secara langsung, tidak memesan nama, dan tidak menjamin nama tersedia.</p>
                </aside>
            </div>

            <div class="col-lg-8">
                <div class="tool-result-head">
                    <div>
                        <span class="eyebrow">Pagar format</span>
                        <h2>{{ $entityTypes[$entityType] }}</h2>
                    </div>
                    <a target="_blank" rel="noopener" href="{{ $rules['source_url'] }}">{{ $rules['basis'] }} ↗</a>
                </div>

                @if($suggestions)
                    <div class="name-result-list">
                        @foreach($suggestions as $suggestion)
                            @php($proposalMessage = 'Saya memilih alternatif nama: '.$suggestion['name'].'. Mohon pengecekan ketersediaan dan kesesuaiannya di AHU.')
                            <article class="name-result-card">
                                <div>
                                    <small>Alternatif {{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</small>
                                    <h3>{{ $suggestion['name'] }}</h3>
                                    <ul>
                                        @foreach($suggestion['checks'] as $check)<li>{{ $check }}</li>@endforeach
                                    </ul>
                                </div>
                                <a class="btn btn-outline-primary" href="{{ route('proposal.create', ['asal' => 'name_generator', 'nama_usaha' => $suggestion['name'], 'pesan' => $proposalMessage]) }}">Minta cek AHU</a>
                            </article>
                        @endforeach
                    </div>
                @else
                    <div class="empty-state tool-empty">
                        <span class="empty-icon">ABC</span>
                        <h2>Masukkan kata utama</h2>
                        <p>Generator akan membuat delapan alternatif. Setelah memilih, lanjutkan ke pengecekan resmi bersama tim.</p>
                    </div>
                @endif

                <div class="legal-source-card mt-4">
                    <strong>Filter yang digunakan</strong>
                    <p>Huruf Latin, kata yang dapat dibaca, struktur nama konservatif per bentuk badan, serta penghindaran angka/huruf tanpa makna. Aturan yang ditampilkan mengikuti status regulasi 2025, tetapi persetujuan dan ketersediaan tetap hanya ditentukan melalui layanan resmi AHU.</p>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection

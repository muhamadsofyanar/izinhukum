@extends('layouts.app')

@section('title', 'Simulasi Bahan Akta Pendirian')
@section('meta_description', 'Rasakan alur pengisian bahan akta PT, CV, dan Yayasan atau pernyataan pendirian Perseroan Perorangan sebelum berkonsultasi.')

@section('content')
<section class="page-hero page-hero-compact">
    <div class="container">
        <span class="eyebrow">Alat edukasi IzinHukum</span>
        <h1>Simulasi bahan dokumen pendirian</h1>
        <p>Isi data non-sensitif untuk melihat struktur ringkasan yang lazim dibahas. Jangan masukkan NIK, nomor identitas, tanda tangan, atau dokumen pribadi.</p>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="row g-4 align-items-start">
            <div class="col-lg-5 simulation-form-column">
                <form class="form-card tool-form" action="{{ route('tools.deed-simulator.run') }}" method="post">
                    @csrf
                    <label class="field">
                        <span>Bentuk yang akan disimulasikan</span>
                        <select class="form-select" id="entity_type" name="entity_type">
                            @foreach($entityTypes as $value => $label)
                                <option value="{{ $value }}" @selected(old('entity_type', $formData['entity_type'] ?? 'pt') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="field">
                        <span>Nama yang diusulkan</span>
                        <input class="form-control" name="proposed_name" value="{{ old('proposed_name', $formData['proposed_name'] ?? '') }}" required maxlength="180" placeholder="Contoh: PT Lentera Digital Nusantara">
                    </label>
                    <label class="field">
                        <span>Tempat kedudukan</span>
                        <input class="form-control" name="domicile" value="{{ old('domicile', $formData['domicile'] ?? '') }}" required maxlength="180" placeholder="Kota/Kabupaten">
                    </label>
                    <label class="field">
                        <span>Maksud, tujuan, dan kegiatan utama</span>
                        <textarea class="form-control" name="activity" rows="4" required maxlength="1500" placeholder="Jelaskan produk, jasa, penerima manfaat, atau kegiatan utama.">{{ old('activity', $formData['activity'] ?? '') }}</textarea>
                    </label>
                    <label class="field">
                        <span>Kandidat kode KBLI (opsional)</span>
                        <input class="form-control" name="kbli_codes" value="{{ old('kbli_codes', $formData['kbli_codes'] ?? '') }}" maxlength="180" placeholder="Contoh: 62010, 63122">
                        <small><a href="{{ route('kbli.index') }}" target="_blank">Cari KBLI terlebih dahulu ↗</a></small>
                    </label>
                    <label class="field">
                        <span>Nama pendiri</span>
                        <textarea class="form-control" name="founder_names" rows="2" required maxlength="600" placeholder="Pisahkan beberapa nama dengan koma. Tanpa NIK.">{{ old('founder_names', $formData['founder_names'] ?? '') }}</textarea>
                    </label>
                    <label class="field">
                        <span>Rencana modal/kekayaan awal</span>
                        <input class="form-control" type="number" name="capital" value="{{ old('capital', $formData['capital'] ?? 10000000) }}" min="0" max="999999999999999" required>
                    </label>
                    <label class="field">
                        <span id="primary-officer-label">Direktur / sekutu aktif / ketua pengurus</span>
                        <input class="form-control" name="primary_officer" value="{{ old('primary_officer', $formData['primary_officer'] ?? '') }}" required maxlength="300">
                    </label>
                    <label class="field">
                        <span id="secondary-officer-label">Komisaris / sekutu komanditer / pembina</span>
                        <input class="form-control" name="secondary_officer" value="{{ old('secondary_officer', $formData['secondary_officer'] ?? '') }}" maxlength="300">
                    </label>
                    <label class="field" id="third-officer-field">
                        <span>Pengawas Yayasan</span>
                        <input class="form-control" name="third_officer" value="{{ old('third_officer', $formData['third_officer'] ?? '') }}" maxlength="300">
                    </label>
                    <label class="consent-line">
                        <input type="checkbox" name="simulation_consent" value="1" required @checked(old('simulation_consent'))>
                        Saya memahami hasil ini hanya simulasi edukatif, bukan akta, minuta, pernyataan pendirian resmi, atau nasihat hukum final.
                    </label>
                    <button class="btn btn-primary" type="submit">Tampilkan simulasi</button>
                </form>
            </div>

            <div class="col-lg-7">
                @if($preview)
                    <article class="simulation-sheet" id="simulation-preview">
                        <div class="simulation-sheet-head">
                            <span>SIMULASI EDUKATIF · BUKAN DOKUMEN RESMI</span>
                            <h2>{{ $preview['title'] }}</h2>
                            <strong>{{ $preview['subtitle'] }}</strong>
                        </div>

                        @foreach($preview['sections'] as $section)
                            <section>
                                <h3>{{ $loop->iteration }}. {{ $section['title'] }}</h3>
                                <dl>
                                    @foreach($section['items'] as $item)
                                        <div><dt>{{ $item['label'] }}</dt><dd>{{ $item['value'] }}</dd></div>
                                    @endforeach
                                </dl>
                            </section>
                        @endforeach

                        <section class="simulation-checklist">
                            <h3>{{ count($preview['sections']) + 1 }}. Pemeriksaan lanjutan</h3>
                            <ul>@foreach($preview['checklist'] as $item)<li>{{ $item }}</li>@endforeach</ul>
                        </section>

                        <footer>
                            <p>Dasar pembacaan: <a target="_blank" rel="noopener" href="{{ $preview['basis']['url'] }}">{{ $preview['basis']['label'] }}</a>.</p>
                            <p>Ringkasan ini disusun dari data yang diisi pengguna. Redaksi, kecukupan data, kewenangan organ, KBLI, nama, dan dokumen pendukung wajib diperiksa oleh tenaga profesional/notaris serta sistem pemerintah yang berwenang.</p>
                        </footer>
                    </article>

                    <div class="simulation-actions mt-3">
                        <button class="btn btn-outline-primary" type="button" onclick="window.print()">Cetak / simpan PDF</button>
                        <a class="btn btn-primary" href="{{ route('proposal.create', ['asal' => 'deed_simulator', 'nama_usaha' => $preview['subtitle'], 'pesan' => $preview['proposal_message']]) }}">Minta diperiksa & ditindaklanjuti</a>
                    </div>
                @else
                    <div class="empty-state tool-empty simulation-empty">
                        <span class="empty-icon">AKTA</span>
                        <h2>Pratinjau akan muncul di sini</h2>
                        <p>Simulasi tidak meminta NIK atau unggahan identitas. Data resmi baru dihimpun melalui alur layanan yang aman setelah ruang lingkup disepakati.</p>
                    </div>
                    <div class="legal-source-card mt-4">
                        <strong>Mengapa disebut “bahan dokumen”?</strong>
                        <p>Akta autentik dibuat oleh pejabat berwenang. Untuk Perseroan Perorangan, pendirian pada dasarnya menggunakan Pernyataan Pendirian elektronik, bukan akta notaris. Karena itu hasil publik ini sengaja dibatasi sebagai ringkasan pembahasan awal.</p>
                    </div>
                @endif
            </div>
        </div>
    </div>
</section>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const select = document.getElementById('entity_type');
    const secondary = document.querySelector('[name="secondary_officer"]');
    const thirdField = document.getElementById('third-officer-field');
    const labels = {
        pt: ['Direktur', 'Komisaris'],
        pt_perorangan: ['Pemegang saham sekaligus direktur', 'Tidak digunakan untuk Perseroan Perorangan'],
        cv: ['Sekutu aktif/pengurus', 'Sekutu komanditer'],
        yayasan: ['Ketua Pengurus', 'Pembina']
    };
    const sync = function () {
        const current = labels[select.value] || labels.pt;
        document.getElementById('primary-officer-label').textContent = current[0];
        document.getElementById('secondary-officer-label').textContent = current[1];
        secondary.disabled = select.value === 'pt_perorangan';
        secondary.required = select.value !== 'pt_perorangan';
        thirdField.hidden = select.value !== 'yayasan';
        thirdField.querySelector('input').required = select.value === 'yayasan';
    };
    select.addEventListener('change', sync);
    sync();
});
</script>
@endpush

@extends('layouts.admin')
@section('title', 'Grup WhatsApp')
@section('heading', 'Grup WhatsApp')
@section('content')
@include('admin.whatsapp._nav')
@php
    $oldSelectedGroupIds = json_decode((string) old('selected_group_ids', '[]'), true);
    $checkedGroupIds = is_array($oldSelectedGroupIds) && count($oldSelectedGroupIds) > 0
        ? array_map('intval', $oldSelectedGroupIds)
        : array_map('intval', $savedGroupIds);
    $activePresetId = $activePreset?->id;
@endphp
<div class="wa-grid">
    <section class="wa-card wa-span-4">
        <h2>Sinkronisasi grup</h2>
        <p class="wa-muted">Daftar grup diambil langsung dari perangkat StarSender memakai Device API Key. Sinkronkan ulang setelah perangkat bergabung atau keluar dari grup.</p>
        <form method="post" action="{{ route('admin.whatsapp.groups.sync') }}" class="wa-form-grid">
            @csrf
            <input type="hidden" name="preset_id" value="{{ $activePresetId }}">
            <div class="full">
                <label>Perangkat</label>
                <select class="form-select" name="device_alias">
                    @foreach(['support'=>'Support','transaction'=>'Transaksi','campaign'=>'Campaign','partner'=>'Mitra','default'=>'Default'] as $key=>$label)
                        <option value="{{ $key }}" @selected($deviceAlias===$key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="full"><button class="btn btn-outline-primary" type="submit">Sinkronkan daftar grup</button></div>
        </form>
        <hr>
        <form method="get" class="wa-form-grid">
            <div class="full">
                <label>Tampilkan grup perangkat</label>
                <select class="form-select" name="device_alias" onchange="this.form.submit()">
                    @foreach(['support'=>'Support','transaction'=>'Transaksi','campaign'=>'Campaign','partner'=>'Mitra','default'=>'Default'] as $key=>$label)
                        <option value="{{ $key }}" @selected($deviceAlias===$key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </section>

    <section class="wa-card wa-span-8">
        <h2>Kategori grup tersimpan</h2>
        <p class="wa-muted">Simpan beberapa kumpulan grup dengan nama berbeda, misalnya Klien, Tamu, Komunitas Bisnis, atau Grup Belajar.</p>

        <form method="get" class="wa-form-grid">
            <input type="hidden" name="device_alias" value="{{ $deviceAlias }}">
            <div class="full">
                <label>Pilih kategori</label>
                <select class="form-select" name="preset_id" onchange="this.form.submit()">
                    <option value="">Kategori baru atau tanpa kategori</option>
                    @foreach($presets as $preset)
                        <option value="{{ $preset->id }}" @selected((int)$activePresetId === (int)$preset->id)>
                            {{ $preset->name }} ({{ count((array)$preset->group_ids) }} grup)
                        </option>
                    @endforeach
                </select>
            </div>
        </form>

        @if($presets->isNotEmpty())
            <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.75rem">
                @foreach($presets as $preset)
                    <a class="btn {{ (int)$activePresetId === (int)$preset->id ? 'btn-primary' : 'btn-outline-secondary' }}"
                       href="{{ route('admin.whatsapp.groups.index', ['device_alias' => $deviceAlias, 'preset_id' => $preset->id]) }}">
                        {{ $preset->name }} · {{ count((array)$preset->group_ids) }} grup
                    </a>
                @endforeach
            </div>
        @endif

        <hr>

        <form method="post" action="{{ route('admin.whatsapp.groups.presets.save') }}" class="wa-form-grid" id="wa-group-preset-form">
            @csrf
            <input type="hidden" name="device_alias" value="{{ $deviceAlias }}">
            <input type="hidden" name="preset_id" value="{{ $activePresetId }}">
            <input type="hidden" name="selected_group_ids" id="wa-preset-selected-group-ids" value="{{ json_encode($checkedGroupIds) }}">
            <div class="full">
                <label>Nama kategori</label>
                <input class="form-control" type="text" name="preset_name" maxlength="100" required
                       value="{{ old('preset_name', $activePreset?->name) }}"
                       placeholder="Contoh: Klien, Tamu, Grup Belajar">
                @error('preset_name')<small class="text-danger">{{ $message }}</small>@enderror
            </div>
            <div class="full">
                <button class="btn btn-primary" id="wa-preset-save-button" type="submit">
                    {{ $activePreset ? 'Perbarui kategori ini' : 'Simpan sebagai kategori baru' }}
                </button>
                @if($activePreset)
                    <a class="btn btn-outline-secondary" href="{{ route('admin.whatsapp.groups.index', ['device_alias' => $deviceAlias]) }}">Buat kategori baru</a>
                @endif
            </div>
        </form>

        @if($activePreset)
            <hr>
            <p class="wa-muted">
                Kategori aktif: <strong>{{ $activePreset->name }}</strong>. Centang atau hapus centang grup di bawah, lalu klik <strong>Perbarui kategori ini</strong> untuk menyimpan perubahan.
            </p>
            <form method="post" action="{{ route('admin.whatsapp.groups.presets.delete', $activePreset) }}" onsubmit="return confirm('Hapus kategori ini? Grup WhatsApp tidak akan terhapus.');">
                @csrf
                @method('delete')
                <button class="btn btn-outline-danger" type="submit">Hapus kategori</button>
            </form>
        @elseif($presets->isEmpty())
            <p class="wa-muted">Belum ada kategori. Centang grup di bawah, isi nama kategori, lalu simpan.</p>
        @endif
    </section>

    <section class="wa-card wa-span-12">
        <h2>Kirim ke banyak grup</h2>
        <p class="wa-muted">
            @if($activePreset)
                Kategori <strong>{{ $activePreset->name }}</strong> sedang dimuat. Anda tetap dapat menambah atau mengurangi centang sebelum mengirim.
            @else
                Pilih grup secara manual atau muat kategori yang sudah disimpan.
            @endif
        </p>
        <form method="post" enctype="multipart/form-data" action="{{ route('admin.whatsapp.groups.send-many') }}" class="wa-form-grid" id="wa-group-send-form">
            @csrf
            <input type="hidden" name="device_alias" value="{{ $deviceAlias }}">
            <input type="hidden" name="preset_id" value="{{ $activePresetId }}">
            <input type="hidden" name="selected_group_ids" id="wa-selected-group-ids" value="{{ json_encode($checkedGroupIds) }}">
            <div class="full wa-group-picker">
                <div class="wa-group-picker-toolbar">
                    <div>
                        <strong>{{ $groups->count() }} grup aktif</strong>
                        <small class="wa-muted" id="wa-selected-count">0 grup dipilih</small>
                    </div>
                    <label><input type="checkbox" id="wa-select-all-groups"> Pilih semua yang tampil</label>
                </div>
                <div class="wa-group-list">
                    @forelse($groups as $group)
                        <label class="wa-group-option">
                            <input type="checkbox" class="wa-group-checkbox" value="{{ $group->id }}" @checked(in_array((int) $group->id, $checkedGroupIds, true))>
                            <span>
                                <strong>{{ $group->name ?: 'Grup tanpa nama' }}</strong>
                                <small><code>{{ $group->group_jid }}</code>@if($group->participant_count !== null) · {{ $group->participant_count }} anggota @endif</small>
                            </span>
                        </label>
                    @empty
                        <p class="wa-muted">Belum ada grup. Klik Sinkronkan daftar grup terlebih dahulu.</p>
                    @endforelse
                </div>
            </div>
            <div>
                <label>Tipe pesan</label>
                <select class="form-select" name="message_type" id="wa-message-type">
                    @foreach(['text'=>'Teks saja','image'=>'Teks + gambar','document'=>'Teks + dokumen','video'=>'Teks + video','audio'=>'Teks + audio','media'=>'Media lain'] as $key=>$label)
                        <option value="{{ $key }}" @selected(old('message_type','text')===$key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label>Jeda antargrup</label>
                <select class="form-select" name="delay_seconds">
                    @foreach([5,10,15,30,60,120,300] as $seconds)
                        <option value="{{ $seconds }}" @selected((int)old('delay_seconds',10)===$seconds)>{{ $seconds < 60 ? $seconds.' detik' : ($seconds / 60).' menit' }}</option>
                    @endforeach
                </select>
            </div>
            <div class="full">
                <label>Jadwal opsional</label>
                <input class="form-control" type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at') }}">
            </div>
            <div class="full">
                <label>Isi pesan atau caption</label>
                <textarea class="form-control" name="body" rows="6" placeholder="Teks ini menjadi isi pesan. Jika memakai gambar, teks menjadi caption gambar.">{{ old('body') }}</textarea>
            </div>
            <div class="full">
                <label>Unggah gambar</label>
                <input class="form-control" type="file" name="media_file" id="wa-media-file" accept="image/jpeg,image/png,image/webp">
                <small class="wa-muted">JPG, JPEG, PNG, atau WEBP. Maksimal 10 MB. File disimpan di server IzinHukum lalu dikirim ke seluruh grup terpilih.</small>
                <div class="wa-media-preview" id="wa-media-preview" hidden>
                    <img id="wa-media-preview-image" alt="Pratinjau gambar">
                    <span id="wa-media-preview-name"></span>
                </div>
            </div>
            <div class="full">
                <label>URL media, opsional</label>
                <input class="form-control" type="url" name="media_url" value="{{ old('media_url') }}" placeholder="https://...">
                <small class="wa-muted">Gunakan untuk dokumen, video, audio, atau gambar yang sudah tersedia di URL publik. Jika gambar diunggah, file unggahan diprioritaskan.</small>
            </div>
            <div class="full">
                <label class="wa-confirm-box"><input type="checkbox" name="confirm_group_policy" value="1" required> Saya memastikan pesan relevan dengan grup yang dipilih dan tidak mengandung spam.</label>
            </div>
            <div class="full"><button class="btn btn-primary" id="wa-group-submit" type="submit" @disabled($groups->isEmpty())>Kirim ke semua grup terpilih</button></div>
        </form>
    </section>

    <section class="wa-card wa-span-12">
        <h2>Riwayat pesan grup</h2>
        <div class="wa-table-wrap"><table class="wa-table"><thead><tr><th>Waktu</th><th>Arah</th><th>Grup</th><th>Isi</th><th>Status</th></tr></thead><tbody>
        @forelse($recentMessages as $message)
            <tr>
                <td>{{ $message->created_at?->format('d/m/Y H:i:s') }}</td>
                <td>{{ $message->direction === 'inbound' ? 'Masuk' : 'Keluar' }}</td>
                <td><strong>{{ $message->recipient_name ?: 'Grup' }}</strong><br><code>{{ $message->phone }}</code></td>
                <td class="wa-message-body">{{ \Illuminate\Support\Str::limit($message->body, 220) }}@if($message->media_url)<br><a href="{{ $message->media_url }}" target="_blank" rel="noopener">Media ↗</a>@endif</td>
                <td><span class="wa-status {{ $message->status }}">{{ $message->status }}</span>@if($message->last_error)<br><small class="text-danger">{{ $message->last_error }}</small>@endif</td>
            </tr>
        @empty
            <tr><td colspan="5" class="wa-muted">Belum ada pesan grup.</td></tr>
        @endforelse
        </tbody></table></div>
        {{ $recentMessages->links() }}
    </section>
</div>
@endsection
@push('scripts')
<script>
(() => {
    const sendForm = document.getElementById('wa-group-send-form');
    const presetForm = document.getElementById('wa-group-preset-form');
    const selectAll = document.getElementById('wa-select-all-groups');
    const checkboxes = Array.from(document.querySelectorAll('.wa-group-checkbox'));
    const selectedIdsInputs = [
        document.getElementById('wa-selected-group-ids'),
        document.getElementById('wa-preset-selected-group-ids'),
    ].filter(Boolean);
    const countLabel = document.getElementById('wa-selected-count');
    const submitButton = document.getElementById('wa-group-submit');
    const presetSaveButton = document.getElementById('wa-preset-save-button');
    const mediaFile = document.getElementById('wa-media-file');
    const preview = document.getElementById('wa-media-preview');
    const previewImage = document.getElementById('wa-media-preview-image');
    const previewName = document.getElementById('wa-media-preview-name');

    const selectedIds = () => checkboxes
        .filter((box) => box.checked)
        .map((box) => Number(box.value));

    const updateCount = () => {
        const ids = selectedIds();
        if (countLabel) countLabel.textContent = `${ids.length} grup dipilih`;
        selectedIdsInputs.forEach((input) => { input.value = JSON.stringify(ids); });
        if (selectAll) {
            selectAll.checked = checkboxes.length > 0 && ids.length === checkboxes.length;
            selectAll.indeterminate = ids.length > 0 && ids.length < checkboxes.length;
        }
    };

    if (selectAll) {
        selectAll.addEventListener('change', () => {
            checkboxes.forEach((box) => { box.checked = selectAll.checked; });
            updateCount();
        });
    }
    checkboxes.forEach((box) => box.addEventListener('change', updateCount));
    updateCount();

    if (mediaFile) {
        mediaFile.addEventListener('change', () => {
            const file = mediaFile.files && mediaFile.files[0];
            if (!file) {
                preview.hidden = true;
                previewImage.removeAttribute('src');
                previewName.textContent = '';
                return;
            }
            previewImage.src = URL.createObjectURL(file);
            previewName.textContent = `${file.name} · ${(file.size / 1024 / 1024).toFixed(2)} MB`;
            preview.hidden = false;
            const type = document.getElementById('wa-message-type');
            if (type) type.value = 'image';
        });
    }

    if (presetForm) {
        presetForm.addEventListener('submit', (event) => {
            const selected = selectedIds().length;
            if (selected === 0) {
                event.preventDefault();
                alert('Pilih minimal satu grup sebelum menyimpan kategori.');
                return;
            }
            if (presetSaveButton) {
                presetSaveButton.disabled = true;
                presetSaveButton.textContent = 'Menyimpan kategori...';
            }
        });
    }

    if (sendForm) {
        sendForm.addEventListener('submit', (event) => {
            const selected = selectedIds().length;
            if (selected === 0) {
                event.preventDefault();
                alert('Pilih minimal satu grup.');
                return;
            }
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.textContent = `Memasukkan ${selected} pesan ke antrean...`;
            }
        });
    }
})();
</script>
@endpush

@extends('layouts.admin')
@section('title', 'Grup WhatsApp')
@section('heading', 'Grup WhatsApp')
@section('content')
@include('admin.whatsapp._nav')
<div class="wa-grid">
    <section class="wa-card wa-span-4">
        <h2>Sinkronisasi grup</h2>
        <p class="wa-muted">Daftar grup diambil langsung dari perangkat StarSender memakai Device API Key. Sinkronkan ulang setelah perangkat bergabung atau keluar dari grup.</p>
        <form method="post" action="{{ route('admin.whatsapp.groups.sync') }}" class="wa-form-grid">
            @csrf
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
        <h2>Kirim ke banyak grup</h2>
        <p class="wa-muted">Pilih maksimal 50 grup dalam satu klik. Sistem membuat satu pesan antrean untuk setiap grup sehingga hasil berhasil atau gagal dapat dilihat per grup.</p>
        <form method="post" action="{{ route('admin.whatsapp.groups.send-many') }}" class="wa-form-grid" id="wa-group-send-form">
            @csrf
            <input type="hidden" name="device_alias" value="{{ $deviceAlias }}">
            <div class="full wa-group-picker">
                <div class="wa-group-picker-toolbar">
                    <strong>{{ $groups->count() }} grup aktif</strong>
                    <label><input type="checkbox" id="wa-select-all-groups"> Pilih semua yang tampil</label>
                </div>
                <div class="wa-group-list">
                    @forelse($groups as $group)
                        <label class="wa-group-option">
                            <input type="checkbox" name="group_ids[]" value="{{ $group->id }}" @checked(in_array($group->id, old('group_ids', [])))>
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
                <select class="form-select" name="message_type">
                    @foreach(['text'=>'Teks','image'=>'Gambar','document'=>'Dokumen','video'=>'Video','audio'=>'Audio','media'=>'Media lain'] as $key=>$label)
                        <option value="{{ $key }}" @selected(old('message_type','text')===$key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label>Jeda antargrup</label>
                <select class="form-select" name="delay_seconds">
                    @foreach([5,10,15,30,60] as $seconds)
                        <option value="{{ $seconds }}" @selected((int)old('delay_seconds',10)===$seconds)>{{ $seconds }} detik</option>
                    @endforeach
                </select>
            </div>
            <div class="full">
                <label>Jadwal opsional</label>
                <input class="form-control" type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at') }}">
            </div>
            <div class="full"><label>Isi pesan</label><textarea class="form-control" name="body" rows="6">{{ old('body') }}</textarea></div>
            <div class="full"><label>URL media opsional</label><input class="form-control" type="url" name="media_url" value="{{ old('media_url') }}" placeholder="https://..."></div>
            <div class="full">
                <label class="wa-confirm-box"><input type="checkbox" name="confirm_group_policy" value="1" required> Saya memastikan pesan relevan dengan grup yang dipilih dan tidak mengandung spam.</label>
            </div>
            <div class="full"><button class="btn btn-primary" type="submit" @disabled($groups->isEmpty())>Kirim ke grup terpilih</button></div>
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
    const selectAll = document.getElementById('wa-select-all-groups');
    if (!selectAll) return;
    const checkboxes = Array.from(document.querySelectorAll('input[name="group_ids[]"]'));
    selectAll.addEventListener('change', () => {
        checkboxes.slice(0, 50).forEach((box) => { box.checked = selectAll.checked; });
    });
})();
</script>
@endpush

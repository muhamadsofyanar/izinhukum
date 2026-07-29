@extends('layouts.admin')
@section('title', 'Alat Provider StarSender')
@section('heading', 'Alat Provider StarSender')
@section('content')
@include('admin.whatsapp._nav')

<div class="wa-tabs-note mb-3">
    Halaman ini menjalankan operasi tulis langsung pada akun StarSender. Gunakan hanya setelah konfigurasi diuji. Semua operasi dicatat pada audit log. Feature flag <strong>Alat provider StarSender</strong> harus aktif.
</div>

@if(!$writeEnabled)
    <div class="alert alert-warning">
        Operasi tulis masih dinonaktifkan. Aktifkan <strong>Alat provider StarSender</strong> pada Pengaturan Fitur setelah Device dan Account API Key diuji.
    </div>
@endif

@if($providerError)
    <div class="alert alert-danger">Daftar grup tidak dapat diambil dari StarSender: {{ $providerError }}</div>
@endif

<div class="wa-grid">
    <section class="wa-card wa-span-4">
        <h2>Status provider</h2>
        <p><span class="wa-status {{ $providerReady ? 'ok' : 'warn' }}">{{ $providerReady ? 'Account API siap' : 'Account API belum siap' }}</span></p>
        <p><span class="wa-status {{ $campaignDeviceReady ? 'ok' : 'warn' }}">{{ $campaignDeviceReady ? 'Device campaign siap' : 'Device campaign belum siap' }}</span></p>
        <p><span class="wa-status {{ $writeEnabled ? 'ok' : 'warn' }}">{{ $writeEnabled ? 'Operasi tulis aktif' : 'Operasi tulis terkunci' }}</span></p>
        <p class="wa-muted">Kredensial tetap berada di Environment Variables Coolify dan tidak ditampilkan pada halaman ini.</p>
    </section>

    <section class="wa-card wa-span-8">
        <h2>Grup kontak provider</h2>
        <div class="wa-table-wrap">
            <table class="wa-table">
                <thead><tr><th>ID</th><th>Nama</th><th>Jumlah</th></tr></thead>
                <tbody>
                @forelse($groups as $group)
                    @php
                        $groupId = data_get($group, 'id') ?? data_get($group, '_id') ?? data_get($group, 'group_id');
                        $groupName = data_get($group, 'name') ?? data_get($group, 'nama') ?? data_get($group, 'group_name') ?? 'Tanpa nama';
                        $groupCount = data_get($group, 'count') ?? data_get($group, 'contacts_count') ?? data_get($group, 'total') ?? '-';
                    @endphp
                    <tr><td><code>{{ $groupId ?: '-' }}</code></td><td>{{ $groupName }}</td><td>{{ $groupCount }}</td></tr>
                @empty
                    <tr><td colspan="3" class="wa-muted">Belum ada data grup, atau Account API belum aktif.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="wa-card wa-span-6">
        <h2>Buat kontak provider</h2>
        <form method="post" action="{{ route('admin.whatsapp.provider-tools.contacts.store') }}" class="wa-form-grid">
            @csrf
            <div><label>Nama</label><input class="form-control" name="name" value="{{ old('name') }}" maxlength="160" required></div>
            <div><label>Nomor WhatsApp</label><input class="form-control" name="number" value="{{ old('number') }}" placeholder="08xxxxxxxxxx" maxlength="32" required></div>
            <div class="full"><label>ID grup, opsional</label><input class="form-control" type="number" name="group_id" value="{{ old('group_id') }}" min="1"></div>
            <div class="full"><label>Variabel kontak, satu nilai per baris</label><textarea class="form-control" name="variables" rows="5" maxlength="3000">{{ old('variables') }}</textarea></div>
            <div class="full"><button class="btn btn-primary" type="submit" @disabled(!$providerReady || !$writeEnabled)>Buat kontak</button></div>
        </form>
    </section>

    <section class="wa-card wa-span-6">
        <h2>Kelola grup kontak</h2>
        <h3>Keluarkan dari grup</h3>
        <form method="post" action="{{ route('admin.whatsapp.provider-tools.contacts.groups.remove') }}" class="wa-form-grid">
            @csrf @method('delete')
            <div><label>Nomor</label><input class="form-control" name="number" maxlength="32" required></div>
            <div><label>ID grup</label><input class="form-control" type="number" name="group_id" min="1" required></div>
            <div class="full"><button class="btn btn-outline-danger" type="submit" @disabled(!$providerReady || !$writeEnabled) onclick="return confirm('Keluarkan kontak dari grup StarSender?')">Keluarkan</button></div>
        </form>
        <hr>
        <h3>Pindahkan antargrup</h3>
        <form method="post" action="{{ route('admin.whatsapp.provider-tools.contacts.groups.move') }}" class="wa-form-grid">
            @csrf
            <div class="full"><label>Nomor</label><input class="form-control" name="number" maxlength="32" required></div>
            <div><label>Dari ID grup</label><input class="form-control" type="number" name="from_group_id" min="1" required></div>
            <div><label>Ke ID grup</label><input class="form-control" type="number" name="to_group_id" min="1" required></div>
            <div class="full"><button class="btn btn-outline-primary" type="submit" @disabled(!$providerReady || !$writeEnabled)>Pindahkan</button></div>
        </form>
    </section>

    <section class="wa-card wa-span-6">
        <h2>Buat campaign provider</h2>
        <p class="wa-muted">Campaign provider StarSender berbeda dari campaign internal IzinHukum. Gunakan campaign internal untuk segmentasi, consent, audit, dan pelaporan yang lebih ketat.</p>
        <form method="post" action="{{ route('admin.whatsapp.provider-tools.campaigns.store') }}" class="wa-form-grid">
            @csrf
            <div class="full"><label>Nama campaign</label><input class="form-control" name="name" maxlength="160" required></div>
            <div class="full"><label>Syntax</label><textarea class="form-control" name="syntax" rows="4" maxlength="1000" required></textarea></div>
            <div class="full"><label>Welcome message</label><textarea class="form-control" name="welcome_message" rows="5" maxlength="3000" required></textarea></div>
            <div class="full"><label>Nomor perangkat campaign</label><input class="form-control" name="number" maxlength="32" placeholder="08xxxxxxxxxx" required></div>
            <div class="full"><button class="btn btn-primary" type="submit" @disabled(!$providerReady || !$campaignDeviceReady || !$writeEnabled)>Buat campaign provider</button></div>
        </form>
    </section>

    <section class="wa-card wa-span-6">
        <h2>Anggota campaign provider</h2>
        <h3>Tambahkan anggota</h3>
        <form method="post" action="{{ route('admin.whatsapp.provider-tools.campaigns.members.store') }}" class="wa-form-grid">
            @csrf
            <div><label>ID campaign</label><input class="form-control" type="number" name="campaign_id" min="1" required></div>
            <div><label>Nomor anggota</label><input class="form-control" name="number" maxlength="32" required></div>
            <div class="full"><label>Syntax</label><textarea class="form-control" name="syntax" rows="4" maxlength="3000" required></textarea></div>
            <div class="full"><label><input type="checkbox" name="welcome_message" value="1" checked> Kirim welcome message</label></div>
            <div class="full"><button class="btn btn-outline-primary" type="submit" @disabled(!$providerReady || !$writeEnabled)>Tambahkan anggota</button></div>
        </form>
        <hr>
        <h3>Pindahkan anggota</h3>
        <form method="post" action="{{ route('admin.whatsapp.provider-tools.campaigns.members.move') }}" class="wa-form-grid">
            @csrf
            <div class="full"><label>Nomor anggota</label><input class="form-control" name="number" maxlength="32" required></div>
            <div><label>Dari ID campaign</label><input class="form-control" type="number" name="campaign_id_from" min="1" required></div>
            <div><label>Ke ID campaign</label><input class="form-control" type="number" name="campaign_id_to" min="1" required></div>
            <div class="full"><button class="btn btn-outline-primary" type="submit" @disabled(!$providerReady || !$writeEnabled)>Pindahkan anggota</button></div>
        </form>
    </section>
</div>
@endsection

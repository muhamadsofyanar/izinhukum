@extends('layouts.admin')

@section('title', 'Email & SMTP')
@section('heading', 'Email & SMTP Mailketing')

@section('content')
<div class="admin-note">Password SMTP disimpan terenkripsi menggunakan APP_KEY. Sender harus sudah diverifikasi/approved di dashboard Mailketing sebelum digunakan.</div>
<section class="admin-panel portal-section">
    <div class="admin-panel-head"><h2>SMTP Account Configuration</h2></div>
    <form class="p-4" action="{{ route('admin.mail.update') }}" method="post">
        @csrf @method('PUT')
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Host</label><input class="form-control" name="host" value="{{ old('host', $settings['host']) }}" placeholder="smtp.mailketing.id" required></div>
            <div class="col-md-3"><label class="form-label">Port</label><input class="form-control" name="port" type="number" min="1" max="65535" value="{{ old('port', $settings['port']) }}" required></div>
            <div class="col-md-3"><label class="form-label">Enkripsi</label><select class="form-select" name="encryption"><option value="tls" @selected($settings['encryption'] === 'tls')>TLS</option><option value="ssl" @selected($settings['encryption'] === 'ssl')>SSL</option><option value="none" @selected($settings['encryption'] === 'none')>Tanpa enkripsi</option></select></div>
            <div class="col-md-6"><label class="form-label">Username</label><input class="form-control" name="username" value="{{ old('username', $settings['username']) }}" required autocomplete="off"></div>
            <div class="col-md-6"><label class="form-label">Password</label><input class="form-control" name="password" type="password" placeholder="{{ $settings['has_password'] ? 'Tersimpan — isi hanya untuk mengganti' : 'Masukkan password SMTP' }}" autocomplete="new-password"></div>
            <div class="col-12"><button class="btn btn-primary" type="submit">Simpan konfigurasi</button></div>
        </div>
    </form>
</section>
<section class="admin-panel portal-section mt-3">
    <div class="admin-panel-head"><h2>Uji pengiriman</h2></div>
    <form class="p-4 d-flex flex-wrap gap-2" action="{{ route('admin.mail.test') }}" method="post">@csrf<input class="form-control flex-grow-1" name="test_email" type="email" value="{{ $currentUser->email }}" required><button class="btn btn-outline-primary" type="submit">Kirim email tes</button></form>
</section>
<section class="admin-panel mt-3">
    <div class="admin-panel-head"><h2>Sender List</h2></div>
    <div class="d-none">@foreach($senders as $sender)<form id="sender-form-{{ $sender->id }}" action="{{ route('admin.mail.senders.update', $sender) }}" method="post">@csrf @method('PUT')</form>@endforeach</div>
    <div class="table-responsive">
        <table class="table admin-table">
            <thead><tr><th>Sender</th><th>Tipe</th><th>Status</th><th>Default</th><th>Aktif</th><th></th></tr></thead>
            <tbody>
            @foreach($senders as $sender)
                <tr>
                    <td><input class="form-control form-control-sm mb-1" form="sender-form-{{ $sender->id }}" name="name" value="{{ $sender->name }}" required><input class="form-control form-control-sm" form="sender-form-{{ $sender->id }}" name="email" type="email" value="{{ $sender->email }}" required></td>
                    <td><select class="form-select form-select-sm" form="sender-form-{{ $sender->id }}" name="type"><option value="simple" @selected($sender->type === 'simple')>Simple</option><option value="whitelabel" @selected($sender->type === 'whitelabel')>Whitelabel</option></select></td>
                    <td><select class="form-select form-select-sm" form="sender-form-{{ $sender->id }}" name="status"><option value="pending" @selected($sender->status === 'pending')>Pending</option><option value="approved" @selected($sender->status === 'approved')>Approved</option><option value="blocked" @selected($sender->status === 'blocked')>Blocked</option></select></td>
                    <td><input form="sender-form-{{ $sender->id }}" type="checkbox" name="is_default" value="1" @checked($sender->is_default)></td>
                    <td><input form="sender-form-{{ $sender->id }}" type="checkbox" name="is_active" value="1" @checked($sender->is_active)></td>
                    <td><button class="btn btn-sm btn-primary" form="sender-form-{{ $sender->id }}" type="submit">Simpan</button></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</section>
<section class="admin-panel portal-section mt-3">
    <div class="admin-panel-head"><h2>Tambah sender</h2></div>
    <form class="p-4" action="{{ route('admin.mail.senders.store') }}" method="post">@csrf
        <div class="row g-3">
            <div class="col-md-3"><input class="form-control" name="name" placeholder="Nama pengirim" required></div>
            <div class="col-md-3"><input class="form-control" name="email" type="email" placeholder="sender@domain.com" required></div>
            <div class="col-md-2"><select class="form-select" name="type"><option value="whitelabel">Whitelabel</option><option value="simple">Simple</option></select></div>
            <div class="col-md-2"><select class="form-select" name="status"><option value="approved">Approved</option><option value="pending">Pending</option><option value="blocked">Blocked</option></select></div>
            <div class="col-md-2 d-flex align-items-center"><label class="check-line"><input type="checkbox" name="is_default" value="1"> Jadikan default</label></div>
            <div class="col-12"><button class="btn btn-primary" type="submit">Tambah sender</button></div>
        </div>
    </form>
</section>
@endsection

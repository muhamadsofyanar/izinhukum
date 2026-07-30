@extends('layouts.admin')
@section('title', 'Pengaturan WhatsApp')
@section('heading', 'Pengaturan WhatsApp')
@section('content')
@include('admin.whatsapp._nav')
@if($settingsWarning)<div class="alert alert-warning"><strong>Pengaturan dibuka dalam mode aman.</strong> {{ $settingsWarning }}</div>@endif
<div class="wa-grid">
    <section class="wa-card wa-span-7">
        <h2>Konfigurasi Coolify</h2>
        <p class="wa-muted">API key tidak dapat dilihat atau diubah dari panel ini. Ini mencegah kredensial tersimpan di database atau GitHub.</p>
        <div class="wa-table-wrap"><table class="wa-table"><tbody>
            @foreach([
                'STARSENDER_ENABLED'=>$integration['enabled'],
                'STARSENDER_ACCOUNT_API_KEY'=>$integration['account_key'],
                'STARSENDER_TRANSACTION_DEVICE_KEY'=>$integration['transaction_key'],
                'STARSENDER_SUPPORT_DEVICE_KEY'=>$integration['support_key'],
                'STARSENDER_PARTNER_DEVICE_KEY'=>$integration['partner_key'],
                'STARSENDER_CAMPAIGN_DEVICE_KEY'=>$integration['campaign_key'],
                'STARSENDER_WEBHOOK_SECRET'=>$integration['webhook_secret'],
                'STARSENDER_ROTATOR_ENABLED'=>$integration['rotator'],
                'STARSENDER_WEBHOOK_PREMIUM_ENABLED'=>$integration['premium_webhook'],
                'STARSENDER_WEBHOOK_MEDIA_ENABLED'=>$integration['media_webhook'],
                'STARSENDER_WEBHOOK_GROUP_ENABLED'=>$integration['group_webhook'],
            ] as $label => $status)
            <tr><td><code>{{ $label }}</code></td><td><span class="wa-status {{ $status ? 'ok' : 'warn' }}">{{ $status ? 'Aktif/tersedia' : 'Belum/nonaktif' }}</span></td></tr>
            @endforeach
            <tr><td>Base URL</td><td><code>{{ $integration['base_url'] }}</code></td></tr>
        </tbody></table></div>
        @if($webhookUrl)
            <p class="mt-3 mb-1"><strong>URL webhook pesan personal</strong></p>
            <code class="wa-code">{{ $webhookUrl }}</code>
            <p class="wa-muted mt-2">Pasang URL yang sama pada pengaturan Webhook device StarSender. Untuk pesan grup, aktifkan Add-On Webhook Group dan gunakan URL ini pada kolom webhook grup.</p>
        @endif
    </section>

    <section class="wa-card wa-span-5">
        <h2>Periksa nomor</h2>
        <form method="post" action="{{ route('admin.whatsapp.settings.check-number') }}" class="wa-form-grid">
            @csrf
            <div class="full"><label>Nomor WhatsApp</label><input class="form-control" name="phone" placeholder="08xxxxxxxxxx" required></div>
            <div class="full"><button class="btn btn-outline-primary" type="submit">Periksa melalui StarSender</button></div>
        </form>
    </section>

    <section class="wa-card wa-span-7">
        <h2>Kirim pesan uji</h2>
        <form method="post" action="{{ route('admin.whatsapp.settings.test-message') }}" class="wa-form-grid">
            @csrf
            <div><label>Nomor tujuan</label><input class="form-control" name="phone" placeholder="08xxxxxxxxxx" required></div>
            <div><label>Perangkat</label><select class="form-select" name="device_alias"><option value="transaction">Transaksi</option><option value="support">Support</option><option value="partner">Mitra</option><option value="campaign">Campaign</option><option value="default">Default</option></select></div>
            <div class="full"><label>Isi pesan</label><textarea class="form-control" name="body" rows="5" required>Pesan uji IzinHukum V11. Mohon abaikan pesan ini.</textarea></div>
            <div class="full"><button class="btn btn-primary" type="submit">Masukkan ke antrean</button></div>
        </form>
    </section>

    <section class="wa-card wa-span-5">
        <h2>Feature flag</h2>
        @foreach($features as $feature)
            <p class="mb-2"><span class="wa-status {{ $feature['enabled'] ? 'ok' : 'warn' }}">{{ $feature['enabled'] ? 'ON' : 'OFF' }}</span> <strong>{{ $feature['label'] }}</strong></p>
        @endforeach
        <a class="btn btn-outline-primary" href="{{ route('admin.features.edit') }}">Ubah feature flag</a>
    </section>

    <section class="wa-card wa-span-12">
        <h2>Consent WhatsApp</h2>
        <p class="wa-muted">Campaign hanya dapat dibuat untuk nomor yang memiliki persetujuan promosi aktif. Simpan bukti persetujuan, misalnya sumber formulir, tanggal, atau catatan percakapan.</p>
        <div class="wa-grid">
            <div class="wa-span-5">
                <form method="post" action="{{ route('admin.whatsapp.settings.consents.store') }}" class="wa-form-grid">
                    @csrf
                    <div><label>Nomor</label><input class="form-control" name="phone" required></div>
                    <div><label>Sumber</label><input class="form-control" name="source" value="admin_record" required></div>
                    <div class="full"><label>Bukti atau catatan</label><textarea class="form-control" name="evidence" rows="4" required></textarea></div>
                    <div><label><input type="checkbox" name="allow_transactional" value="1" checked> Transaksi</label></div>
                    <div><label><input type="checkbox" name="allow_marketing" value="1"> Promosi</label></div>
                    <div class="full"><button class="btn btn-primary">Catat persetujuan</button></div>
                </form>
            </div>
            <div class="wa-span-7 wa-table-wrap">
                <table class="wa-table"><thead><tr><th>Nomor</th><th>Jenis</th><th>Sumber</th><th>Tanggal</th><th></th></tr></thead><tbody>
                @forelse($consents as $consent)
                    <tr><td><code>{{ $consent->phone }}</code></td><td>Transaksi: {{ $consent->allow_transactional ? 'Ya' : 'Tidak' }}<br>Promosi: {{ $consent->marketingActive() ? 'Aktif' : 'Tidak aktif' }}</td><td>{{ $consent->source }}<br><small>{{ \Illuminate\Support\Str::limit($consent->evidence,80) }}</small></td><td>{{ $consent->consented_at?->format('d/m/Y H:i') }}</td><td>@if($consent->marketingActive())<form method="post" action="{{ route('admin.whatsapp.settings.consents.revoke',$consent) }}">@csrf @method('delete')<button class="btn btn-sm btn-outline-danger">Cabut promosi</button></form>@endif</td></tr>
                @empty<tr><td colspan="5" class="wa-muted">Belum ada consent yang dicatat.</td></tr>@endforelse
                </tbody></table>
                {{ $consents->links() }}
            </div>
        </div>
    </section>
</div>
@endsection

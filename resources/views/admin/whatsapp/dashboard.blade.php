@extends('layouts.admin')
@section('title', 'Pusat WhatsApp')
@section('heading', 'Pusat WhatsApp')
@section('header_action')
    <a class="btn btn-primary" href="{{ route('admin.whatsapp.inbox.index') }}">Buka percakapan</a>
@endsection
@section('content')
@include('admin.whatsapp._nav')

@if(!$ready)
    <div class="alert alert-warning">Data WhatsApp belum siap. Pastikan proses pembaruan database telah selesai.</div>
@endif

<section class="wa-dashboard-hero">
    <div>
        <span>Aktivitas hari ini</span>
        <h2>Kelola pesan dari satu tempat</h2>
        <p>Buka chat yang perlu dibalas, lanjutkan peluang penjualan, atau siapkan pengiriman berikutnya.</p>
    </div>
    <div class="wa-dashboard-actions">
        <a class="btn btn-primary" href="{{ route('admin.whatsapp.inbox.index', ['status' => 'pending']) }}">Lihat yang perlu dibalas</a>
        <a class="btn btn-outline-primary" href="{{ route('admin.whatsapp.campaigns.index') }}">Buat kampanye</a>
    </div>
</section>

<div class="wa-priority-stats">
    <a href="{{ route('admin.whatsapp.inbox.index') }}">
        <span>Belum dibaca</span>
        <strong>{{ number_format($stats['unread'], 0, ',', '.') }}</strong>
        <small>Buka percakapan →</small>
    </a>
    <a href="{{ route('admin.whatsapp.messages.index') }}">
        <span>Terkirim hari ini</span>
        <strong>{{ number_format($stats['sent_today'], 0, ',', '.') }}</strong>
        <small>Lihat riwayat →</small>
    </a>
    <a href="{{ route('admin.whatsapp.messages.index', ['status' => 'queued']) }}">
        <span>Dalam antrean</span>
        <strong>{{ number_format($stats['queued'], 0, ',', '.') }}</strong>
        <small>Periksa antrean →</small>
    </a>
    <a class="{{ $stats['failed_today'] > 0 ? 'attention' : '' }}" href="{{ route('admin.whatsapp.messages.index', ['status' => 'failed']) }}">
        <span>Gagal dikirim</span>
        <strong>{{ number_format($stats['failed_today'], 0, ',', '.') }}</strong>
        <small>{{ $stats['failed_today'] > 0 ? 'Perlu diperiksa →' : 'Tidak ada masalah' }}</small>
    </a>
</div>

<div class="wa-dashboard-grid">
    <section class="wa-card wa-quick-panel">
        <header>
            <div><span>Jalan pintas</span><h2>Mau mengerjakan apa?</h2></div>
        </header>
        <div class="wa-quick-links">
            <a href="{{ route('admin.whatsapp.inbox.index') }}"><strong>Balas percakapan</strong><small>Baca pesan personal dan grup</small><b>→</b></a>
            <a href="{{ route('admin.whatsapp.contacts.index') }}"><strong>Kelola kontak</strong><small>Tambah kontak, label, dan data pelanggan</small><b>→</b></a>
            <a href="{{ route('admin.whatsapp.groups.index') }}"><strong>Kirim ke grup</strong><small>Pilih grup dan kirim pesan bersama</small><b>→</b></a>
            <a href="{{ route('admin.whatsapp.leads.index') }}"><strong>Lanjutkan peluang</strong><small>Pantau prospek sampai menjadi order</small><b>→</b></a>
        </div>
    </section>

    <section class="wa-card wa-business-panel">
        <header><div><span>Data kerja</span><h2>Gambaran singkat</h2></div></header>
        <dl>
            <div><dt>Kontak tersimpan</dt><dd>{{ number_format($stats['contacts'], 0, ',', '.') }}</dd></div>
            <div><dt>Peluang aktif</dt><dd>{{ number_format($stats['active_leads'], 0, ',', '.') }}</dd></div>
            <div><dt>Kampanye berjalan</dt><dd>{{ number_format($stats['campaigns'], 0, ',', '.') }}</dd></div>
            <div><dt>Dokumen perlu diperiksa</dt><dd>{{ number_format($stats['documents_pending'], 0, ',', '.') }}</dd></div>
        </dl>
    </section>

    <section class="wa-card wa-latest-panel">
        <header>
            <div><span>Aktivitas terbaru</span><h2>Pesan terakhir</h2></div>
            <a href="{{ route('admin.whatsapp.messages.index') }}">Lihat semua</a>
        </header>
        <div class="wa-table-wrap">
            <table class="wa-table">
                <thead><tr><th>Waktu</th><th>Kontak atau tujuan</th><th>Isi pesan</th><th>Status</th></tr></thead>
                <tbody>
                @forelse($latestMessages as $message)
                    <tr>
                        <td>{{ $message->created_at?->format('d/m H:i') }}</td>
                        <td><strong>{{ $message->recipient_name ?: $message->phone }}</strong><small>{{ $message->direction === 'inbound' ? 'Masuk' : 'Keluar' }}</small></td>
                        <td class="wa-message-body">{{ \Illuminate\Support\Str::limit($message->body ?: 'Lampiran '.$message->message_type, 90) }}</td>
                        <td><span class="wa-status {{ $message->status }}">{{ $message->status }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="wa-muted">Belum ada aktivitas pesan.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>

<details class="wa-system-details">
    <summary>
        <span><strong>Status sistem</strong><small>Koneksi, perangkat, dan fitur teknis</small></span>
        <em>{{ collect($integration)->except('queue')->every(fn ($value) => (bool) $value) ? 'Siap' : 'Perlu diperiksa' }}</em>
    </summary>
    <div class="wa-system-grid">
        <section>
            <h3>Kesiapan koneksi</h3>
            <div class="wa-table-wrap"><table class="wa-table"><tbody>
                @foreach([
                    'Integrasi WhatsApp'=>$integration['environment_enabled'],
                    'Account API Key'=>$integration['account_key'],
                    'Perangkat transaksi'=>$integration['transaction_key'],
                    'Perangkat support'=>$integration['support_key'],
                    'Perangkat kampanye'=>$integration['campaign_key'],
                    'Keamanan webhook'=>$integration['webhook_secret'],
                ] as $label => $value)
                    <tr><td>{{ $label }}</td><td><span class="wa-status {{ $value ? 'ok' : 'bad' }}">{{ $value ? 'Siap' : 'Belum' }}</span></td></tr>
                @endforeach
                <tr><td>Sistem antrean</td><td><span class="wa-status {{ $integration['queue'] === 'database' ? 'ok' : 'warn' }}">{{ $integration['queue'] }}</span></td></tr>
            </tbody></table></div>
            @if($webhookUrl)
                <p class="wa-muted mb-1">URL webhook StarSender</p><code class="wa-code">{{ $webhookUrl }}</code>
            @endif
        </section>
        <section>
            <h3>Fitur yang tersedia</h3>
            <div class="wa-feature-list">
                @foreach($features as $feature)
                    <div><span>{{ $feature['label'] }}</span><b class="wa-status {{ $feature['enabled'] ? 'ok' : 'warn' }}">{{ $feature['enabled'] ? 'Aktif' : 'Nonaktif' }}</b></div>
                @endforeach
            </div>
            <div class="wa-system-actions">
                <a class="btn btn-outline-primary" href="{{ route('admin.whatsapp.settings.index') }}">Pengaturan WhatsApp</a>
                <a class="btn btn-outline-primary" href="{{ route('admin.features.edit') }}">Kelola fitur</a>
            </div>
        </section>
    </div>
</details>
@endsection

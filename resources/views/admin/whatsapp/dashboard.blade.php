@extends('layouts.admin')
@section('title', 'WhatsApp & CRM')
@section('heading', 'WhatsApp & CRM')
@section('header_action')
<a class="btn btn-primary" href="{{ route('admin.whatsapp.settings.index') }}">Konfigurasi</a>
@endsection
@section('content')
@include('admin.whatsapp._nav')

@if(!$ready)
<div class="alert alert-warning">Tabel V11 belum tersedia. Pastikan migration deployment telah selesai.</div>
@endif

<div class="wa-grid">
    @foreach([
        ['label'=>'Antrean aktif','value'=>$stats['queued']],
        ['label'=>'Terkirim hari ini','value'=>$stats['sent_today']],
        ['label'=>'Gagal hari ini','value'=>$stats['failed_today']],
        ['label'=>'Pesan belum dibaca','value'=>$stats['unread']],
        ['label'=>'Campaign aktif','value'=>$stats['campaigns']],
        ['label'=>'Opt-out','value'=>$stats['opt_outs']],
    ] as $stat)
    <section class="wa-card wa-stat wa-span-4"><strong>{{ number_format($stat['value'], 0, ',', '.') }}</strong><span>{{ $stat['label'] }}</span></section>
    @endforeach

    <section class="wa-card wa-span-6">
        <h2>Kesiapan integrasi</h2>
        <div class="wa-table-wrap"><table class="wa-table"><tbody>
            @foreach([
                'Environment STARSENDER_ENABLED'=>$integration['environment_enabled'],
                'Account API Key'=>$integration['account_key'],
                'Device key transaksi'=>$integration['transaction_key'],
                'Device key support'=>$integration['support_key'],
                'Device key campaign'=>$integration['campaign_key'],
                'Webhook secret'=>$integration['webhook_secret'],
            ] as $label => $value)
            <tr><td>{{ $label }}</td><td><span class="wa-status {{ $value ? 'ok' : 'bad' }}">{{ $value ? 'Siap' : 'Belum' }}</span></td></tr>
            @endforeach
            <tr><td>Queue connection</td><td><span class="wa-status {{ $integration['queue']==='database' ? 'ok' : 'warn' }}">{{ $integration['queue'] }}</span></td></tr>
        </tbody></table></div>
        @if($webhookUrl)
            <p class="wa-muted mb-1">Webhook StarSender</p><code class="wa-code">{{ $webhookUrl }}</code>
        @else
            <div class="alert alert-warning mt-3">Isi STARSENDER_WEBHOOK_SECRET di Coolify untuk menghasilkan URL webhook.</div>
        @endif
    </section>

    <section class="wa-card wa-span-6">
        <h2>Aktivasi aman</h2>
        <ol class="wa-checklist">
            <li>Isi API key dan secret hanya di Coolify.</li>
            <li>Aktifkan feature flag <strong>Integrasi WhatsApp</strong>.</li>
            <li>Kirim satu pesan uji ke nomor internal.</li>
            <li>Hubungkan webhook dan uji Inbox.</li>
            <li>Aktifkan notifikasi transaksi satu per satu.</li>
            <li>Campaign dan rotator tetap nonaktif sampai consent diverifikasi.</li>
        </ol>
        <p class="wa-tabs-note mt-3">Seluruh otomasi hasil migration berada dalam kondisi nonaktif. Deployment tidak langsung mengirim pesan ke pelanggan.</p>
    </section>

    <section class="wa-card wa-span-12">
        <h2>Feature flag WhatsApp</h2>
        <div class="wa-table-wrap"><table class="wa-table"><thead><tr><th>Fitur</th><th>Status</th><th>Keterangan</th></tr></thead><tbody>
        @foreach($features as $feature)
            <tr><td>{{ $feature['label'] }}</td><td><span class="wa-status {{ $feature['enabled'] ? 'ok' : 'warn' }}">{{ $feature['enabled'] ? 'Aktif' : 'Nonaktif' }}</span></td><td>{{ $feature['description'] }}</td></tr>
        @endforeach
        </tbody></table></div>
        <a class="btn btn-outline-primary mt-3" href="{{ route('admin.features.edit') }}">Kelola feature flag</a>
    </section>

    <section class="wa-card wa-span-12">
        <h2>Pesan terakhir</h2>
        <div class="wa-table-wrap"><table class="wa-table"><thead><tr><th>Waktu</th><th>Arah</th><th>Tujuan</th><th>Isi</th><th>Status</th></tr></thead><tbody>
        @forelse($latestMessages as $message)
            <tr><td>{{ $message->created_at?->format('d/m/Y H:i') }}</td><td>{{ $message->direction }}</td><td>{{ $message->recipient_name ?: $message->phone }}</td><td class="wa-message-body">{{ \Illuminate\Support\Str::limit($message->body, 120) }}</td><td><span class="wa-status {{ $message->status }}">{{ $message->status }}</span></td></tr>
        @empty<tr><td colspan="5" class="wa-muted">Belum ada pesan.</td></tr>@endforelse
        </tbody></table></div>
    </section>
</div>
@endsection

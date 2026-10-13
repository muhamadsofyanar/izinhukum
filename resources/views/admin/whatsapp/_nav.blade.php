@php
    $marketingActive = request()->routeIs('admin.whatsapp.campaigns.*')
        || request()->routeIs('admin.whatsapp.templates.*')
        || request()->routeIs('admin.whatsapp.sequences.*')
        || request()->routeIs('admin.whatsapp.automations.*');
    $contentActive = request()->routeIs('admin.whatsapp.groups.*')
        || request()->routeIs('admin.whatsapp.documents.*')
        || request()->routeIs('admin.whatsapp.faq.*')
        || request()->routeIs('admin.whatsapp.messages.*');
    $systemActive = request()->routeIs('admin.whatsapp.webhooks.*')
        || request()->routeIs('admin.whatsapp.devices.*')
        || request()->routeIs('admin.whatsapp.provider-tools.*')
        || request()->routeIs('admin.whatsapp.settings.*');
@endphp
<nav class="wa-nav" aria-label="Menu pusat WhatsApp">
    <div class="wa-nav-main">
        <a class="{{ request()->routeIs('admin.whatsapp.dashboard') ? 'active' : '' }}" href="{{ route('admin.whatsapp.dashboard') }}">Ringkasan</a>
        <a class="{{ request()->routeIs('admin.whatsapp.inbox.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.inbox.index') }}">Percakapan</a>
        <a class="{{ request()->routeIs('admin.whatsapp.contacts.*') || request()->routeIs('admin.whatsapp.labels.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.contacts.index') }}">Kontak</a>
        <a class="{{ request()->routeIs('admin.whatsapp.leads.*') || request()->routeIs('admin.whatsapp.requirements.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.leads.index') }}">Peluang (CRM)</a>
    </div>
    <div class="wa-nav-groups">
        <details class="wa-nav-group {{ $marketingActive ? 'active' : '' }}">
            <summary>Pemasaran</summary>
            <div class="wa-nav-menu">
                <a class="{{ request()->routeIs('admin.whatsapp.campaigns.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.campaigns.index') }}"><strong>Kampanye</strong><small>Kirim pesan ke banyak penerima</small></a>
                <a class="{{ request()->routeIs('admin.whatsapp.templates.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.templates.index') }}"><strong>Template pesan</strong><small>Simpan format pesan siap pakai</small></a>
                <a class="{{ request()->routeIs('admin.whatsapp.sequences.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.sequences.index') }}"><strong>Pesan bertahap</strong><small>Atur rangkaian tindak lanjut</small></a>
                <a class="{{ request()->routeIs('admin.whatsapp.automations.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.automations.index') }}"><strong>Balasan otomatis</strong><small>Atur pemicu dan respons otomatis</small></a>
            </div>
        </details>
        <details class="wa-nav-group {{ $contentActive ? 'active' : '' }}">
            <summary>Grup & Materi</summary>
            <div class="wa-nav-menu">
                <a class="{{ request()->routeIs('admin.whatsapp.groups.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.groups.index') }}"><strong>Grup WhatsApp</strong><small>Sinkronkan dan kirim ke grup</small></a>
                <a class="{{ request()->routeIs('admin.whatsapp.documents.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.documents.index') }}"><strong>Dokumen</strong><small>Kelola arsip untuk dikirim</small></a>
                <a class="{{ request()->routeIs('admin.whatsapp.faq.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.faq.index') }}"><strong>Jawaban FAQ</strong><small>Bank jawaban pertanyaan umum</small></a>
                <a class="{{ request()->routeIs('admin.whatsapp.messages.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.messages.index') }}"><strong>Riwayat pengiriman</strong><small>Periksa status semua pesan</small></a>
            </div>
        </details>
        <details class="wa-nav-group {{ $systemActive ? 'active' : '' }}">
            <summary>Pengaturan & Sistem</summary>
            <div class="wa-nav-menu wa-nav-menu-right">
                <a class="{{ request()->routeIs('admin.whatsapp.settings.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.settings.index') }}"><strong>Pengaturan utama</strong><small>Koneksi dan pengujian WhatsApp</small></a>
                <a class="{{ request()->routeIs('admin.whatsapp.devices.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.devices.index') }}"><strong>Perangkat</strong><small>Kelola nomor pengirim</small></a>
                <a class="{{ request()->routeIs('admin.whatsapp.webhooks.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.webhooks.index') }}"><strong>Log pesan masuk</strong><small>Diagnostik webhook untuk teknisi</small></a>
                <a class="{{ request()->routeIs('admin.whatsapp.provider-tools.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.provider-tools.index') }}"><strong>Alat StarSender</strong><small>Fitur lanjutan provider</small></a>
            </div>
        </details>
    </div>
</nav>

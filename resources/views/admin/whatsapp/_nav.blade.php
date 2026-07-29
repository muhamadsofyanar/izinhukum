<nav class="wa-nav" aria-label="Menu WhatsApp">
    <a class="{{ request()->routeIs('admin.whatsapp.dashboard') ? 'active' : '' }}" href="{{ route('admin.whatsapp.dashboard') }}">Ringkasan</a>
    <a class="{{ request()->routeIs('admin.whatsapp.inbox.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.inbox.index') }}">Inbox</a>
    <a class="{{ request()->routeIs('admin.whatsapp.messages.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.messages.index') }}">Pesan</a>
    <a class="{{ request()->routeIs('admin.whatsapp.templates.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.templates.index') }}">Template</a>
    <a class="{{ request()->routeIs('admin.whatsapp.automations.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.automations.index') }}">Otomasi</a>
    <a class="{{ request()->routeIs('admin.whatsapp.campaigns.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.campaigns.index') }}">Campaign</a>
    <a class="{{ request()->routeIs('admin.whatsapp.devices.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.devices.index') }}">Perangkat</a>
    <a class="{{ request()->routeIs('admin.whatsapp.provider-tools.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.provider-tools.index') }}">Alat provider</a>
    <a class="{{ request()->routeIs('admin.whatsapp.settings.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.settings.index') }}">Pengaturan</a>
</nav>

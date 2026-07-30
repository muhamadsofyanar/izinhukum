<nav class="wa-nav" aria-label="Menu WhatsApp dan CRM">
    <a class="{{ request()->routeIs('admin.whatsapp.dashboard') ? 'active' : '' }}" href="{{ route('admin.whatsapp.dashboard') }}">Ringkasan</a>
    <a class="{{ request()->routeIs('admin.whatsapp.inbox.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.inbox.index') }}">Inbox</a>
    <a class="{{ request()->routeIs('admin.whatsapp.contacts.*') || request()->routeIs('admin.whatsapp.labels.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.contacts.index') }}">Kontak</a>
    <a class="{{ request()->routeIs('admin.whatsapp.leads.*') || request()->routeIs('admin.whatsapp.requirements.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.leads.index') }}">CRM</a>
    <a class="{{ request()->routeIs('admin.whatsapp.sequences.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.sequences.index') }}">Sequence</a>
    <a class="{{ request()->routeIs('admin.whatsapp.documents.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.documents.index') }}">Dokumen</a>
    <a class="{{ request()->routeIs('admin.whatsapp.messages.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.messages.index') }}">Pesan</a>
    <a class="{{ request()->routeIs('admin.whatsapp.groups.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.groups.index') }}">Grup</a>
    <a class="{{ request()->routeIs('admin.whatsapp.templates.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.templates.index') }}">Template</a>
    <a class="{{ request()->routeIs('admin.whatsapp.faq.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.faq.index') }}">FAQ</a>
    <a class="{{ request()->routeIs('admin.whatsapp.automations.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.automations.index') }}">Otomasi</a>
    <a class="{{ request()->routeIs('admin.whatsapp.campaigns.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.campaigns.index') }}">Campaign</a>
    <a class="{{ request()->routeIs('admin.whatsapp.webhooks.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.webhooks.index') }}">Webhook</a>
    <a class="{{ request()->routeIs('admin.whatsapp.devices.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.devices.index') }}">Perangkat</a>
    <a class="{{ request()->routeIs('admin.whatsapp.provider-tools.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.provider-tools.index') }}">Alat provider</a>
    <a class="{{ request()->routeIs('admin.whatsapp.settings.*') ? 'active' : '' }}" href="{{ route('admin.whatsapp.settings.index') }}">Pengaturan</a>
</nav>

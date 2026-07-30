@extends('layouts.admin')
@section('title', 'Percakapan WhatsApp')
@section('heading', 'Pusat WhatsApp')
@section('header_action')
    <a class="btn btn-outline-primary" href="{{ route('admin.whatsapp.contacts.index') }}">Kontak</a>
@endsection
@section('content')
@include('admin.whatsapp._nav')

@php
    $displayName = $conversation->display_name ?: ($conversation->channel === 'group' ? 'Grup WhatsApp' : $conversation->phone);
    $statusLabels = [
        'open' => 'Terbuka',
        'pending' => 'Perlu dibalas',
        'waiting_customer' => 'Menunggu jawaban',
        'closed' => 'Selesai',
    ];
@endphp
<section class="wa-inbox-shell has-selection">
    @include('admin.whatsapp._inbox_sidebar')

    <main class="wa-chat-panel">
        <header class="wa-chat-header">
            <a class="wa-mobile-back" href="{{ route('admin.whatsapp.inbox.index') }}" aria-label="Kembali ke daftar percakapan">‹</a>
            <span class="wa-chat-avatar {{ $conversation->channel === 'group' ? 'group' : '' }}" aria-hidden="true">{{ $conversation->channel === 'group' ? 'G' : mb_strtoupper(mb_substr($displayName, 0, 1)) }}</span>
            <div>
                <h2>{{ $displayName }}</h2>
                <p>{{ $conversation->channel === 'group' ? 'Grup WhatsApp' : $conversation->phone }} · {{ $conversation->device_alias ?: 'support' }}</p>
            </div>
            <span class="wa-header-state {{ $conversation->status }}">{{ $statusLabels[$conversation->status] ?? ucfirst($conversation->status) }}</span>
        </header>

        @if($conversation->channel === 'group')
            <div class="wa-group-notice"><strong>Pesan grup</strong><span>Balasan akan dikirim kepada seluruh anggota melalui perangkat {{ $conversation->device_alias ?: 'support' }}.</span></div>
        @endif

        <div class="wa-conversation" id="conversation-messages">
            @forelse($conversation->messages as $message)
                <article class="wa-bubble {{ $message->direction }}">
                    @if($message->channel === 'group' && $message->direction === 'inbound')
                        @php($senderLabel = data_get($message->metadata, 'sender_name') ?: data_get($message->metadata, 'sender_phone'))
                        @if($senderLabel)<strong class="wa-sender-label">{{ $senderLabel }}</strong>@endif
                    @endif
                    @if($message->body)<div class="wa-message-body">{{ $message->body }}</div>@endif
                    @if($message->crmDocument?->path)
                        <a class="wa-message-attachment" href="{{ route('admin.whatsapp.documents.download', $message->crmDocument) }}">Buka dokumen</a>
                    @elseif($message->media_url)
                        <a class="wa-message-attachment" href="{{ $message->media_url }}" target="_blank" rel="noopener">Buka media ↗</a>
                    @endif
                    <small>{{ $message->created_at?->format('H:i') }} · {{ $message->direction === 'inbound' ? 'diterima' : $message->status }}</small>
                </article>
            @empty
                <div class="wa-chat-empty wa-chat-empty-center"><strong>Belum ada pesan</strong><p>Kirim pesan pertama melalui kolom di bawah.</p></div>
            @endforelse
        </div>

        <form method="post" action="{{ route('admin.whatsapp.inbox.reply', $conversation) }}" class="wa-composer">
            @csrf
            <details class="wa-attachment-picker">
                <summary title="Tambahkan lampiran" aria-label="Tambahkan lampiran">+</summary>
                <div class="wa-attachment-menu">
                    <label>Jenis pesan
                        <select class="form-select" name="message_type">
                            <option value="text">Teks</option>
                            <option value="image">Gambar</option>
                            <option value="document">Dokumen</option>
                            <option value="video">Video</option>
                            <option value="audio">Audio</option>
                            <option value="media">Media lain</option>
                        </select>
                    </label>
                    <label>Dokumen tersimpan
                        <select class="form-select" name="crm_document_id">
                            <option value="">Tanpa dokumen</option>
                            @foreach($vaultDocuments as $document)<option value="{{ $document->id }}">{{ $document->name }}{{ $document->original_name ? ' · '.$document->original_name : '' }}</option>@endforeach
                        </select>
                    </label>
                    <label>Atau URL media
                        <input class="form-control" type="url" name="media_url" placeholder="https://...">
                    </label>
                    <small>Pilih dokumen tersimpan atau URL media, jangan keduanya.</small>
                </div>
            </details>
            <label class="visually-hidden" for="wa-message-body">Isi pesan</label>
            <textarea id="wa-message-body" name="body" rows="1" placeholder="Ketik pesan"></textarea>
            <button class="wa-send-button" title="Kirim pesan" aria-label="Kirim pesan">Kirim</button>
        </form>
    </main>

    <aside class="wa-detail-panel">
        <header>
            <div>
                <span>Detail percakapan</span>
                <h2>{{ $conversation->channel === 'group' ? 'Informasi grup' : 'Informasi kontak' }}</h2>
            </div>
        </header>

        <dl class="wa-detail-list">
            <div><dt>{{ $conversation->channel === 'group' ? 'JID grup' : 'Nomor WhatsApp' }}</dt><dd>{{ $conversation->phone }}</dd></div>
            <div><dt>Jenis chat</dt><dd>{{ $conversation->channel === 'group' ? 'Grup' : 'Personal' }}</dd></div>
            <div><dt>Perangkat</dt><dd>{{ $conversation->device_alias ?: 'support' }}</dd></div>
            @if($conversation->contact)<div><dt>Kontak CRM</dt><dd><a href="{{ route('admin.whatsapp.contacts.show', $conversation->contact) }}">{{ $conversation->contact->name ?: $conversation->contact->phone }}</a></dd></div>@endif
            @if($conversation->lead)<div><dt>Peluang</dt><dd>{{ $conversation->lead->title }} · {{ $conversation->lead->stageLabel() }}</dd></div>@endif
            @if($conversation->channel !== 'group')
                <div><dt>Order</dt><dd>{{ $conversation->serviceOrder?->order_number ?: '-' }}</dd></div>
                <div><dt>Proposal</dt><dd>{{ $conversation->inquiry?->reference ?: '-' }}</dd></div>
            @endif
        </dl>

        @if($conversation->contact && $conversation->contact->labels->isNotEmpty())
            <div class="wa-label-list">@foreach($conversation->contact->labels as $label)<span class="wa-label" style="--label-color:{{ $label->color }}">{{ $label->name }}</span>@endforeach</div>
        @endif

        <form method="post" action="{{ route('admin.whatsapp.inbox.update', $conversation) }}" class="wa-detail-form">
            @csrf
            @method('put')
            <label>Status percakapan
                <select class="form-select" name="status">@foreach($statusLabels as $key => $label)<option value="{{ $key }}" @selected($conversation->status === $key)>{{ $label }}</option>@endforeach</select>
            </label>
            <label>Penanggung jawab
                <select class="form-select" name="assigned_to">
                    <option value="">Belum ditugaskan</option>
                    @foreach($admins as $admin)<option value="{{ $admin->id }}" @selected($conversation->assigned_to === $admin->id)>{{ $admin->name }}</option>@endforeach
                </select>
            </label>
            <label>Label internal
                <input class="form-control" name="labels" value="{{ implode(', ', $conversation->labels ?? []) }}" placeholder="contoh: prioritas, follow-up">
            </label>
            @if($conversation->channel !== 'group')
                <label class="wa-check-option"><input type="checkbox" name="is_ai_blocked" value="1" @checked($conversation->is_ai_blocked)> Nonaktifkan balasan AI</label>
            @endif
            <button class="btn btn-primary">Simpan perubahan</button>
        </form>

        @if($conversation->channel !== 'group')
            <details class="wa-advanced-settings">
                <summary>Pengaturan AI provider</summary>
                <p>Gunakan hanya jika nomor perlu diblokir langsung pada sistem StarSender.</p>
                <div class="wa-inline-actions">
                    <form method="post" action="{{ route('admin.whatsapp.inbox.ai-blacklist', $conversation) }}">@csrf<input type="hidden" name="blocked" value="1"><button class="btn btn-sm btn-outline-danger">Blokir AI</button></form>
                    <form method="post" action="{{ route('admin.whatsapp.inbox.ai-blacklist', $conversation) }}">@csrf<input type="hidden" name="blocked" value="0"><button class="btn btn-sm btn-outline-primary">Buka blokir</button></form>
                </div>
            </details>
        @endif
    </aside>
</section>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const messages = document.getElementById('conversation-messages');
        if (messages) messages.scrollTop = messages.scrollHeight;
    });
</script>
@endpush

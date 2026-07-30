@php
    $statusLabels = [
        'open' => 'Terbuka',
        'pending' => 'Perlu dibalas',
        'waiting_customer' => 'Menunggu jawaban',
        'closed' => 'Selesai',
    ];
    $selectedConversationId = isset($conversation) ? $conversation->id : null;
    $activeFilters = collect([$status, $channel])->filter()->count();
@endphp
<aside class="wa-inbox-list" aria-label="Daftar percakapan">
    <header class="wa-inbox-list-head">
        <div>
            <h2>Percakapan</h2>
            @if($conversations->total() > 0)<span>{{ number_format($conversations->total(), 0, ',', '.') }} chat</span>@endif
        </div>
        <a class="wa-icon-button" href="{{ route('admin.whatsapp.contacts.index') }}" title="Buka daftar kontak" aria-label="Buka daftar kontak">+</a>
    </header>

    <form class="wa-inbox-search" method="get">
        <label class="visually-hidden" for="wa-inbox-search">Cari percakapan</label>
        <span aria-hidden="true">⌕</span>
        <input id="wa-inbox-search" name="q" value="{{ $search }}" placeholder="Cari nama atau nomor">
        @if($status)<input type="hidden" name="status" value="{{ $status }}">@endif
        @if($channel)<input type="hidden" name="channel" value="{{ $channel }}">@endif
    </form>

    <details class="wa-filter-drawer" @if($activeFilters) open @endif>
        <summary>
            <span>Filter percakapan</span>
            @if($activeFilters)<small>{{ $activeFilters }} aktif</small>@else<small>Semua</small>@endif
        </summary>
        <form method="get" class="wa-filter-form">
            @if($search)<input type="hidden" name="q" value="{{ $search }}">@endif
            <label>Status
                <select class="form-select" name="status">
                    <option value="">Semua status</option>
                    @foreach($statusLabels as $key => $label)<option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>@endforeach
                </select>
            </label>
            <label>Jenis chat
                <select class="form-select" name="channel">
                    <option value="">Personal dan grup</option>
                    <option value="personal" @selected($channel === 'personal')>Personal</option>
                    <option value="group" @selected($channel === 'group')>Grup</option>
                </select>
            </label>
            <div class="wa-filter-actions">
                <a href="{{ route('admin.whatsapp.inbox.index') }}">Reset</a>
                <button class="btn btn-sm btn-primary">Terapkan</button>
            </div>
        </form>
    </details>

    <div class="wa-chat-list">
        @forelse($conversations as $item)
            @php
                $displayName = $item->display_name ?: ($item->channel === 'group' ? 'Grup WhatsApp' : 'Nomor belum dikenal');
                $messagePreview = $item->latestMessage?->body;
                if (!$messagePreview && $item->latestMessage) {
                    $messagePreview = 'Lampiran '.$item->latestMessage->message_type;
                }
                $query = array_filter(['q' => $search, 'status' => $status, 'channel' => $channel], fn ($value) => $value !== '');
            @endphp
            <a class="wa-chat-item {{ $selectedConversationId === $item->id ? 'active' : '' }}" href="{{ route('admin.whatsapp.inbox.show', array_merge(['conversation' => $item], $query)) }}">
                <span class="wa-chat-avatar {{ $item->channel === 'group' ? 'group' : '' }}" aria-hidden="true">{{ $item->channel === 'group' ? 'G' : mb_strtoupper(mb_substr($displayName, 0, 1)) }}</span>
                <span class="wa-chat-copy">
                    <span class="wa-chat-row">
                        <strong>{{ $displayName }}</strong>
                        <time>{{ $item->last_message_at?->isToday() ? $item->last_message_at->format('H:i') : $item->last_message_at?->format('d/m') }}</time>
                    </span>
                    <span class="wa-chat-row">
                        <small>{{ \Illuminate\Support\Str::limit($messagePreview ?: ($item->channel === 'group' ? 'Percakapan grup' : $item->phone), 42) }}</small>
                        @if($item->unread_count > 0)<b>{{ $item->unread_count > 99 ? '99+' : $item->unread_count }}</b>@endif
                    </span>
                    <span class="wa-chat-meta">
                        <em class="wa-conversation-state {{ $item->status }}">{{ $statusLabels[$item->status] ?? ucfirst($item->status) }}</em>
                        @if($item->assignee)<span>{{ $item->assignee->name }}</span>@endif
                    </span>
                </span>
            </a>
        @empty
            <div class="wa-chat-empty">
                <strong>Tidak ada percakapan</strong>
                <p>Coba ubah kata pencarian atau reset filter.</p>
            </div>
        @endforelse
    </div>

    @if($conversations->hasPages())
        <footer class="wa-inbox-pagination">{{ $conversations->onEachSide(0)->links() }}</footer>
    @endif
</aside>

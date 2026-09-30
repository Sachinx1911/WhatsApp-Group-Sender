<div class="relative" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false"
    wire:poll.30s="checkForNew">
    <button type="button" x-on:click="open = !open"
        class="relative grid size-10 place-items-center rounded-xl text-muted transition hover:bg-canvas hover:text-ink"
        aria-label="Notifications{{ $this->unreadCount ? " ({$this->unreadCount} unread)" : '' }}">
        <x-lucide-bell class="size-5" />
        @if ($this->unreadCount)
            <span class="absolute right-1.5 top-1.5 grid min-w-4 place-items-center rounded-full bg-danger px-1 text-[10px] font-semibold leading-4 text-white">
                {{ $this->unreadCount > 9 ? '9+' : $this->unreadCount }}
            </span>
        @endif
    </button>

    <div x-show="open" x-cloak x-transition.opacity.duration.150ms
        class="absolute right-0 top-full z-50 mt-2 w-[min(22rem,calc(100vw-2rem))] rounded-2xl border border-line bg-white shadow-xl">
        <div class="flex items-center justify-between border-b border-line px-4 py-3">
            <p class="font-semibold">Notifications</p>
            @if ($this->unreadCount)
                <button type="button" wire:click="markAllRead" class="text-xs font-medium text-primary hover:underline">Mark all as read</button>
            @endif
        </div>

        <ul class="max-h-96 divide-y divide-line overflow-y-auto">
            @forelse ($this->notifications as $notification)
                @php
                    [$icon, $tone] = match ($notification->type) {
                        'campaign_completed' => ['circle-check', 'bg-success-soft text-success'],
                        'campaign_failures' => ['triangle-alert', 'bg-danger-soft text-danger'],
                        'whatsapp_disconnected', 'queue_stopped' => ['wifi-off', 'bg-danger-soft text-danger'],
                        'campaign_cancelled' => ['circle-slash', 'bg-slate-100 text-muted'],
                        default => ['bell', 'bg-primary-soft text-primary'],
                    };
                @endphp
                <li>
                    <button type="button" wire:click="open({{ $notification->id }})"
                        class="flex w-full gap-3 px-4 py-3 text-left transition hover:bg-canvas">
                        <span class="grid size-8 shrink-0 place-items-center rounded-lg {{ $tone }}">
                            <x-dynamic-component :component="'lucide-'.$icon" class="size-4" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-2">
                                <span class="truncate text-[13px] font-medium">{{ $notification->title }}</span>
                                @unless ($notification->read_at)
                                    <span class="size-1.5 shrink-0 rounded-full bg-primary" title="Unread"></span>
                                @endunless
                            </span>
                            @if ($notification->body)
                                <span class="mt-0.5 line-clamp-2 block text-xs text-muted">{{ $notification->body }}</span>
                            @endif
                            <span class="mt-1 block text-[11px] text-slate-400">{{ $notification->created_at->diffForHumans() }}</span>
                        </span>
                    </button>
                </li>
            @empty
                <li class="px-4 py-10 text-center text-[13px] text-muted">You're all caught up.</li>
            @endforelse
        </ul>
    </div>
</div>

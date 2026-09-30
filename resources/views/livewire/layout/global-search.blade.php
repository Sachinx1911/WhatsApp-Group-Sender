<div class="relative min-w-0 max-w-md flex-1" x-data="{ open: false }"
    x-on:click.outside="open = false"
    x-on:keydown.escape="open = false; $refs.input.blur()"
    x-on:keydown.window.ctrl.k.prevent="$refs.input.focus()">
    <x-lucide-search class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-muted" />
    <input x-ref="input" wire:model.live.debounce.300ms="query" type="search"
        x-on:focus="open = true" x-on:input="open = true"
        placeholder="Search by group, message, error..." autocomplete="off" aria-label="Global search"
        class="w-full rounded-xl border border-line bg-canvas py-2 pl-10 pr-14 text-sm outline-none transition placeholder:text-slate-400 focus:border-primary focus:bg-white focus:ring-4 focus:ring-primary/10">
    <kbd x-show="!$wire.query" class="pointer-events-none absolute right-3 top-1/2 hidden -translate-y-1/2 rounded-md border border-line bg-white px-1.5 py-0.5 text-[10px] font-medium text-muted md:block">Ctrl K</kbd>

    <div x-show="open && $wire.query.trim().length >= 2" x-cloak x-transition.opacity.duration.150ms
        class="absolute left-0 right-0 top-full z-50 mt-2 max-h-[70vh] overflow-y-auto rounded-2xl border border-line bg-white p-2 shadow-xl sm:right-auto sm:w-[28rem]">
        <div wire:loading.delay wire:target="query" class="px-3 py-2 text-[13px] text-muted">Searching...</div>

        <div wire:loading.remove wire:target="query">
            @forelse ($this->sections as $section)
                <div class="py-1">
                    <div class="flex items-center justify-between px-3 py-1.5">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-muted">{{ $section['label'] }}</p>
                        <a href="{{ $section['all_url'] }}" class="text-xs font-medium text-primary hover:underline">View all</a>
                    </div>
                    @foreach ($section['items'] as $item)
                        <a href="{{ $item['url'] }}" class="flex items-center gap-3 rounded-xl px-3 py-2 transition hover:bg-canvas">
                            <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-primary-soft text-primary">
                                <x-dynamic-component :component="'lucide-'.$section['icon']" class="size-4" />
                            </span>
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium">{{ $item['title'] }}</span>
                                <span class="block truncate text-xs text-muted">{{ $item['meta'] }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            @empty
                <div class="px-3 py-6 text-center">
                    <p class="text-sm font-medium">No results for “{{ $query }}”</p>
                    <p class="mt-1 text-xs text-muted">Try a group name, category, message text or error.</p>
                </div>
            @endforelse
        </div>
    </div>
</div>

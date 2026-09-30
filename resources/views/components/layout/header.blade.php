<header class="sticky top-0 z-20 flex h-[68px] items-center gap-3 border-b border-line bg-white/95 px-4 backdrop-blur sm:px-6">
    <button type="button" x-data x-on:click="$store.sidebar.toggle()"
        class="grid size-10 shrink-0 place-items-center rounded-xl text-muted transition hover:bg-canvas hover:text-ink" aria-label="Toggle menu">
        <x-lucide-menu class="size-5" />
    </button>

    <livewire:layout.global-search />

    <div class="ml-auto flex items-center gap-1 sm:gap-2">
        <livewire:layout.header-status />
        <livewire:layout.notification-bell />

        {{-- Admin menu --}}
        <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
            <button type="button" x-on:click="open = !open"
                class="flex items-center gap-2.5 rounded-xl py-1 pl-1 pr-2 transition hover:bg-canvas" aria-label="Account menu">
                <span class="grid size-9 place-items-center rounded-full bg-accent-soft text-sm font-semibold text-accent">
                    {{ Str::upper(Str::substr(auth()->user()->name, 0, 1)) }}
                </span>
                <span class="hidden text-left leading-tight xl:block">
                    <span class="block text-sm font-medium">{{ auth()->user()->name }}</span>
                    <span class="block text-xs text-muted">Education Hub</span>
                </span>
                <x-lucide-chevron-down class="hidden size-4 text-muted xl:block" />
            </button>

            <div x-show="open" x-cloak x-transition.opacity.duration.150ms
                class="absolute right-0 top-full z-50 mt-2 w-56 rounded-2xl border border-line bg-white p-1.5 shadow-xl">
                <div class="border-b border-line px-3 pb-2.5 pt-1.5">
                    <p class="truncate text-sm font-medium">{{ auth()->user()->name }}</p>
                    <p class="truncate text-xs text-muted">{{ auth()->user()->email }}</p>
                </div>
                <a href="{{ route('settings.index') }}" class="mt-1 flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm hover:bg-canvas">
                    <x-lucide-settings class="size-4 text-muted" /> Settings
                </a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-danger hover:bg-danger-soft">
                        <x-lucide-log-out class="size-4" /> Logout
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>

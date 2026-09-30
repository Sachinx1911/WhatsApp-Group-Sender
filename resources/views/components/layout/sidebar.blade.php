{{-- Data comes from the view composer in AppServiceProvider: $navItems, $failedCount, $storage --}}
<aside x-data :data-open="$store.sidebar.mobileOpen || null"
    class="fixed inset-y-0 left-0 z-40 flex w-60 -translate-x-full flex-col bg-navy text-slate-300 transition-[transform,width] duration-200 data-open:translate-x-0 lg:translate-x-0 lg:collapsed:w-[76px]">

    {{-- Brand --}}
    <div class="flex h-[68px] shrink-0 items-center gap-3 border-b border-white/5 px-5 lg:collapsed:justify-center lg:collapsed:px-0">
        <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-primary text-white">
            <x-lucide-graduation-cap class="size-5" />
        </span>
        <div class="min-w-0 lg:collapsed:hidden">
            <p class="truncate font-semibold leading-tight text-white">Education Hub</p>
            <p class="truncate text-[11px] text-slate-400">WhatsApp Group Sender</p>
        </div>
        <button type="button" x-on:click="$store.sidebar.mobileOpen = false"
            class="ml-auto rounded-lg p-1 text-slate-400 hover:bg-white/10 hover:text-white lg:hidden" aria-label="Close menu">
            <x-lucide-x class="size-5" />
        </button>
    </div>

    {{-- Navigation --}}
    <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4" aria-label="Main">
        @foreach ($navItems as $item)
            @php
                // "groups.index" is active on every "groups.*" page (e.g. group details).
                $active = request()->routeIs(Str::contains($item['route'], '.') ? Str::before($item['route'], '.').'.*' : $item['route']);
            @endphp
            <a href="{{ route($item['route']) }}" title="{{ $item['label'] }}"
                @if ($active) aria-current="page" @endif
                @class([
                    'group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition lg:collapsed:justify-center lg:collapsed:px-0',
                    'bg-white/10 font-medium text-white' => $active,
                    'text-slate-400 hover:bg-white/5 hover:text-white' => ! $active,
                ])>
                @if ($active)
                    <span class="absolute inset-y-2 left-0 w-1 rounded-r-full bg-primary"></span>
                @endif
                <x-dynamic-component :component="'lucide-'.$item['icon']"
                    :class="$active ? 'size-5 shrink-0 text-blue-400' : 'size-5 shrink-0'" />
                <span class="truncate lg:collapsed:hidden">{{ $item['label'] }}</span>

                @if ($item['route'] === 'failed.index' && $failedCount > 0)
                    <span class="ml-auto rounded-full bg-danger px-1.5 text-[11px] font-semibold leading-5 text-white lg:collapsed:absolute lg:collapsed:right-2 lg:collapsed:top-1 lg:collapsed:ml-0 lg:collapsed:leading-4">
                        {{ $failedCount > 99 ? '99+' : $failedCount }}
                    </span>
                @endif
            </a>
        @endforeach
    </nav>

    {{-- Storage usage --}}
    <div class="shrink-0 p-3">
        <div class="rounded-xl bg-white/5 p-4 lg:collapsed:hidden">
            <div class="flex items-center gap-2 text-[13px] font-medium text-white">
                <x-lucide-hard-drive class="size-4 text-slate-400" />
                Storage Usage
            </div>
            <p class="mt-2 text-xs text-slate-400">
                <span class="font-medium text-slate-200">{{ $storage['used_label'] }}</span> / {{ $storage['quota_label'] }}
                <span class="float-right">{{ rtrim(rtrim(number_format($storage['percent'], 1), '0'), '.') }}%</span>
            </p>
            <x-ui.progress-bar :value="$storage['percent']" :tone="$storage['percent'] >= 90 ? 'danger' : 'light'" size="sm" class="mt-2" />
        </div>
        <div class="hidden place-items-center py-2 text-[11px] text-slate-400 lg:collapsed:grid" title="Storage: {{ $storage['used_label'] }} / {{ $storage['quota_label'] }}">
            <x-lucide-hard-drive class="size-4" />
            {{ round($storage['percent']) }}%
        </div>
    </div>
</aside>

{{-- Mobile backdrop --}}
<div x-data x-show="$store.sidebar.mobileOpen" x-cloak x-transition.opacity x-on:click="$store.sidebar.mobileOpen = false"
    class="fixed inset-0 z-30 bg-navy/50 lg:hidden"></div>

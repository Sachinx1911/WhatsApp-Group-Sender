{{-- Right-side panel. Open/close with the same 'open-modal' / 'close-modal' events as the modal. --}}
@props([
    'name',
    'title' => null,
    'width' => 'max-w-md',
])

<div x-data="{ open: false }" x-init="$watch('open', value => trackModal(@js($name), value))"
    x-on:open-modal.window="if (modalName($event.detail) === @js($name)) open = true"
    x-on:close-modal.window="if (modalName($event.detail) === @js($name)) open = false"
    x-on:keydown.escape.window="if (open && isTopModal(@js($name))) open = false"
    x-show="open" x-cloak class="fixed inset-0 z-50" role="dialog" aria-modal="true">
    <div x-show="open" x-transition.opacity class="absolute inset-0 bg-navy/40" x-on:click="open = false"></div>

    <aside x-show="open" x-trap.noscroll="open"
        x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
        x-transition:leave="transition duration-150 ease-in" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
        {{ $attributes->class("absolute inset-y-0 right-0 flex w-full {$width} flex-col bg-white shadow-xl") }}>
        <header class="flex items-center justify-between border-b border-line px-5 py-4">
            <h2 class="text-base font-semibold">{{ $title }}</h2>
            <button type="button" x-on:click="open = false" class="rounded-lg p-1 text-muted hover:bg-canvas hover:text-ink" aria-label="Close">
                <x-lucide-x class="size-5" />
            </button>
        </header>
        <div class="flex-1 overflow-y-auto p-5">{{ $slot }}</div>
        @isset($footer)
            <footer class="flex flex-wrap justify-end gap-2 border-t border-line px-5 py-4">{{ $footer }}</footer>
        @endisset
    </aside>
</div>

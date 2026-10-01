{{--
    Centered dialog. Open with $dispatch('open-modal', 'name') or
    Livewire $this->dispatch('open-modal', 'name'); close with 'close-modal'.
--}}
@props([
    'name',
    'title' => null,
    'maxWidth' => 'max-w-lg',
])

<div x-data="{ open: false }" x-init="$watch('open', value => trackModal(@js($name), value))"
    x-on:open-modal.window="if (modalName($event.detail) === @js($name)) open = true"
    x-on:close-modal.window="if (modalName($event.detail) === @js($name)) open = false"
    x-on:keydown.escape.window="if (open && isTopModal(@js($name))) open = false"
    x-show="open" x-cloak
    class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center"
    role="dialog" aria-modal="true" @if ($title) aria-label="{{ $title }}" @endif>
    <div x-show="open" x-transition.opacity class="absolute inset-0 bg-navy/50" x-on:click="open = false"></div>

    <div x-show="open" x-transition x-trap.noscroll="open"
        {{ $attributes->class("relative flex max-h-[calc(100dvh-2rem)] w-full {$maxWidth} flex-col rounded-2xl bg-white shadow-xl") }}>
        @if ($title)
            <header class="flex shrink-0 items-center justify-between border-b border-line px-5 py-4">
                <h2 class="text-base font-semibold">{{ $title }}</h2>
                <button type="button" x-on:click="open = false" class="rounded-lg p-1 text-muted hover:bg-canvas hover:text-ink" aria-label="Close">
                    <x-lucide-x class="size-5" />
                </button>
            </header>
        @endif

        <div class="min-h-0 flex-1 overflow-y-auto p-5">{{ $slot }}</div>

        @isset($footer)
            <footer class="flex shrink-0 flex-wrap justify-end gap-2 border-t border-line px-5 py-4">{{ $footer }}</footer>
        @endisset
    </div>
</div>

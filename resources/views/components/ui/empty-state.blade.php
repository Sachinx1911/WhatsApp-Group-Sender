@props([
    'icon' => 'inbox',
    'title',
])

<div {{ $attributes->class('flex flex-col items-center justify-center px-6 py-12 text-center') }}>
    <span class="grid size-14 place-items-center rounded-2xl bg-primary-soft text-primary">
        <x-dynamic-component :component="'lucide-'.$icon" class="size-6" />
    </span>
    <h3 class="mt-4 text-[15px] font-semibold">{{ $title }}</h3>
    @if ($slot->isNotEmpty())
        <p class="mt-1 max-w-sm text-[13px] text-muted">{{ $slot }}</p>
    @endif
    @isset($actions)
        <div class="mt-5 flex flex-wrap justify-center gap-2">{{ $actions }}</div>
    @endisset
</div>

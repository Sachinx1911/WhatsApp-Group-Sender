@props([
    'title',
    'subtitle' => null,
])

<div {{ $attributes->class('mb-6 flex flex-wrap items-end justify-between gap-4') }}>
    <div class="min-w-0">
        <h1 class="text-[28px] font-semibold leading-tight tracking-tight lg:text-[30px]">{{ $title }}</h1>
        @if ($subtitle)
            <p class="mt-1 text-muted">{{ $subtitle }}</p>
        @endif
    </div>

    @if ($slot->isNotEmpty())
        <div class="flex flex-wrap items-center gap-2">{{ $slot }}</div>
    @endif
</div>

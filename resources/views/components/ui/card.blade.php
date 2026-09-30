@props([
    'title' => null,
    'subtitle' => null,
    'padding' => true,
])

<section {{ $attributes->class('flex flex-col rounded-card border border-line bg-white shadow-card') }}>
    @if ($title || isset($actions))
        <header class="flex flex-wrap items-start justify-between gap-3 border-b border-line px-5 py-4">
            <div class="min-w-0">
                <h2 class="text-[15px] font-semibold">{{ $title }}</h2>
                @if ($subtitle)
                    <p class="mt-0.5 text-[13px] text-muted">{{ $subtitle }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="flex items-center gap-2">{{ $actions }}</div>
            @endisset
        </header>
    @endif

    <div @class(['min-h-0 flex-1', 'p-5' => $padding])>
        {{ $slot }}
    </div>
</section>

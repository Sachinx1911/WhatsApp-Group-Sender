@props([
    'label',
    'value',
    'icon',
    'tone' => 'primary', // primary | success | warning | danger | accent | muted
    'hint' => null,
    'href' => null,
])

@php
    $iconTone = match ($tone) {
        'success' => 'bg-success-soft text-success',
        'warning' => 'bg-warning-soft text-warning',
        'danger' => 'bg-danger-soft text-danger',
        'accent' => 'bg-accent-soft text-accent',
        'muted' => 'bg-canvas text-muted',
        default => 'bg-primary-soft text-primary',
    };
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" @endif
    {{ $attributes->class([
        'block rounded-card border border-line bg-white p-5 shadow-card',
        'transition hover:border-primary/30 hover:shadow-md' => $href,
    ]) }}>
    <div class="flex items-start justify-between gap-3">
        <p class="pt-1 text-[13px] text-muted">{{ $label }}</p>
        <span class="grid size-9 shrink-0 place-items-center rounded-xl {{ $iconTone }}">
            <x-dynamic-component :component="'lucide-'.$icon" class="size-[18px]" />
        </span>
    </div>
    <p class="mt-1 text-[22px] font-semibold leading-tight">{{ $value }}</p>
    @if ($hint)
        <p class="mt-1 text-xs text-muted">{{ $hint }}</p>
    @endif
</{{ $tag }}>

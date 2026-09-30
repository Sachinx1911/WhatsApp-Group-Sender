@props([
    'variant' => 'primary', // primary | secondary | danger | ghost
    'size' => 'md',         // sm | md
    'icon' => null,         // lucide icon name
    'href' => null,
    'type' => 'button',
])

@php
    $classes = [
        'inline-flex shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-xl font-medium transition focus:outline-none focus-visible:ring-4 disabled:cursor-not-allowed disabled:opacity-60',
        match ($size) {
            'sm' => 'px-3 py-1.5 text-[13px]',
            default => 'px-4 py-2.5 text-sm',
        },
        match ($variant) {
            'secondary' => 'border border-line bg-white text-ink hover:bg-canvas focus-visible:ring-primary/10',
            'danger' => 'bg-danger text-white hover:bg-red-600 focus-visible:ring-danger/20',
            'ghost' => 'text-muted hover:bg-canvas hover:text-ink focus-visible:ring-primary/10',
            default => 'bg-primary text-white hover:bg-primary-hover focus-visible:ring-primary/20',
        },
    ];
    $iconClass = $size === 'sm' ? 'size-3.5' : 'size-4';
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>
        @if ($icon)<x-dynamic-component :component="'lucide-'.$icon" :class="$iconClass" />@endif
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>
        @if ($icon)<x-dynamic-component :component="'lucide-'.$icon" :class="$iconClass" />@endif
        {{ $slot }}
    </button>
@endif

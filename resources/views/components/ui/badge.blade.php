@props([
    'color' => 'muted', // success | danger | warning | primary | accent | muted
    'dot' => false,
])

@php
    [$pill, $dotColor] = match ($color) {
        'success' => ['bg-success-soft text-emerald-700', 'bg-success'],
        'danger' => ['bg-danger-soft text-red-700', 'bg-danger'],
        'warning' => ['bg-warning-soft text-amber-700', 'bg-warning'],
        'primary' => ['bg-primary-soft text-blue-700', 'bg-primary'],
        'accent' => ['bg-accent-soft text-violet-700', 'bg-accent'],
        default => ['bg-slate-100 text-slate-600', 'bg-slate-400'],
    };
@endphp

<span {{ $attributes->class("inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium {$pill}") }}>
    @if ($dot)
        <span class="size-1.5 rounded-full {{ $dotColor }}"></span>
    @endif
    {{ $slot }}
</span>

@props([
    'value' => 0, // percent 0-100
    'tone' => 'primary',
    'size' => 'md', // sm | md
])

@php
    $bar = match ($tone) {
        'success' => 'bg-success',
        'warning' => 'bg-warning',
        'danger' => 'bg-danger',
        'light' => 'bg-blue-400',
        default => 'bg-primary',
    };
    $value = max(0, min(100, (float) $value));
@endphp

<div {{ $attributes->class([
    'w-full overflow-hidden rounded-full',
    'h-1.5' => $size === 'sm',
    'h-2' => $size !== 'sm',
    'bg-slate-100' => $tone !== 'light',
    'bg-white/10' => $tone === 'light',
]) }} role="progressbar" aria-valuenow="{{ $value }}" aria-valuemin="0" aria-valuemax="100">
    <div class="h-full rounded-full transition-[width] duration-500 {{ $bar }}" style="width: {{ $value }}%"></div>
</div>

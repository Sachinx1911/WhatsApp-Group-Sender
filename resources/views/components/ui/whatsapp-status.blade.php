{{-- WhatsApp connection pill. Links to the connection settings. --}}
@props(['status'])

@php
    $classes = match ($status->color()) {
        'success' => 'border-emerald-200 bg-success-soft text-emerald-700',
        'warning' => 'border-amber-200 bg-warning-soft text-amber-700',
        default => 'border-red-200 bg-danger-soft text-red-700',
    };
    $dot = match ($status->color()) {
        'success' => 'bg-success',
        'warning' => 'bg-warning animate-pulse',
        default => 'bg-danger',
    };
@endphp

<a href="{{ route('settings.index') }}#whatsapp"
    {{ $attributes->class("inline-flex items-center gap-2 whitespace-nowrap rounded-full border px-2.5 py-2.5 text-[13px] font-medium transition hover:brightness-95 sm:px-3 sm:py-1.5 {$classes}") }}
    title="WhatsApp {{ $status->label() }}">
    <span class="size-2 shrink-0 rounded-full {{ $dot }}"></span>
    <span class="sr-only sm:not-sr-only">WhatsApp {{ $status->label() }}</span>
</a>

{{-- Clickable column header for tables using App\Livewire\Concerns\WithTable. --}}
@props([
    'column',
    'sortBy',
    'sortDir',
    'align' => 'left',
])

@php $active = $sortBy === $column; @endphp

<th {{ $attributes->class(['px-3 py-2.5 font-medium', 'text-right' => $align === 'right']) }}
    @if ($active) aria-sort="{{ $sortDir === 'asc' ? 'ascending' : 'descending' }}" @endif>
    <button type="button" wire:click="sortBy('{{ $column }}')"
        @class(['inline-flex items-center gap-1 whitespace-nowrap transition hover:text-ink', 'text-ink' => $active, 'flex-row-reverse' => $align === 'right'])>
        {{ $slot }}
        @if ($active)
            @if ($sortDir === 'asc')
                <x-lucide-arrow-up class="size-3.5" />
            @else
                <x-lucide-arrow-down class="size-3.5" />
            @endif
        @else
            <x-lucide-arrow-up-down class="size-3.5 opacity-40" />
        @endif
    </button>
</th>

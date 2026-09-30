{{-- Swatch picker bound to a Livewire property ($model). --}}
<div class="flex flex-wrap items-center gap-1.5" role="radiogroup" aria-label="Category color">
    @foreach (\App\Models\Category::PALETTE as $color)
        <button type="button" wire:click="$set('{{ $model }}', '{{ $color }}')" role="radio" aria-checked="{{ $selected === $color ? 'true' : 'false' }}"
            aria-label="Color {{ $color }}"
            @class(['size-6 rounded-full ring-offset-2 transition', 'ring-2 ring-ink' => $selected === $color, 'hover:scale-110' => $selected !== $color])
            style="background-color: {{ $color }}"></button>
    @endforeach
</div>

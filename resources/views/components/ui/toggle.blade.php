{{-- On/off switch with a label. Pass wire:model through attributes. --}}
@props([
    'label',
    'description' => null,
])

<label class="flex cursor-pointer items-start justify-between gap-6 py-3.5">
    <span class="min-w-0">
        <span class="block text-sm font-medium">{{ $label }}</span>
        @if ($description)
            <span class="mt-0.5 block text-xs text-muted">{{ $description }}</span>
        @endif
    </span>
    <span class="relative mt-0.5 inline-flex shrink-0">
        <input type="checkbox" role="switch" {{ $attributes->merge(['class' => 'peer sr-only']) }}>
        <span class="h-6 w-11 rounded-full bg-slate-200 transition peer-checked:bg-primary peer-focus-visible:ring-4 peer-focus-visible:ring-primary/20"></span>
        <span class="absolute left-0.5 top-0.5 size-5 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
    </span>
</label>

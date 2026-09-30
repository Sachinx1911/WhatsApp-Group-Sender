<div>
    <x-ui.modal :name="\App\Livewire\Media\Picker::MODAL" title="Choose from Media Library" max-width="max-w-3xl">
        <div class="mb-4 flex flex-wrap gap-2">
            <div class="relative min-w-[200px] flex-1">
                <x-lucide-search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
                <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search by file name" aria-label="Search media"
                    class="w-full rounded-xl border border-line py-2 pl-9 pr-3 text-sm outline-none focus:border-primary focus:ring-4 focus:ring-primary/10">
            </div>
            <div class="flex rounded-xl border border-line p-0.5 text-[13px]">
                @foreach (['' => 'All', 'image' => 'Images', 'pdf' => 'PDFs'] as $value => $label)
                    <button type="button" wire:click="$set('type', '{{ $value }}')"
                        @class(['rounded-lg px-3 py-1.5 transition', 'bg-primary text-white' => $type === $value, 'text-muted hover:text-ink' => $type !== $value])>{{ $label }}</button>
                @endforeach
            </div>
        </div>

        @if ($items->isEmpty())
            <x-ui.empty-state icon="image" title="No media found">
                Upload images and PDFs in the Media Library.
            </x-ui.empty-state>
        @else
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
                @foreach ($items as $item)
                    <button type="button" wire:key="pick-{{ $item->id }}" wire:click="choose({{ $item->id }})"
                        class="group overflow-hidden rounded-xl border border-line bg-white text-left transition hover:border-primary hover:ring-4 hover:ring-primary/10">
                        <span class="block aspect-square bg-canvas">
                            @if ($item->isImage())
                                <img src="{{ route('media.thumbnail', $item) }}" alt="" loading="lazy" class="size-full object-cover">
                            @else
                                <span class="grid size-full place-items-center">
                                    <span class="grid h-14 w-11 place-items-center rounded-md bg-red-500 text-xs font-bold text-white shadow">PDF</span>
                                </span>
                            @endif
                        </span>
                        <span class="block px-2.5 py-2">
                            <span class="block truncate text-xs font-medium group-hover:text-primary">{{ $item->original_name }}</span>
                            <span class="block text-[11px] text-muted">{{ $item->type->label() }} · {{ $item->humanSize() }}</span>
                        </span>
                    </button>
                @endforeach
            </div>

            @if ($items->hasPages())
                <div class="mt-4 flex items-center justify-between text-[13px] text-muted">
                    <span>Page {{ $items->currentPage() }} of {{ $items->lastPage() }}</span>
                    <div class="flex gap-2">
                        <x-ui.button variant="secondary" size="sm" wire:click="previousPage('mediaPage')" :disabled="$items->onFirstPage()">Previous</x-ui.button>
                        <x-ui.button variant="secondary" size="sm" wire:click="nextPage('mediaPage')" :disabled="! $items->hasMorePages()">Next</x-ui.button>
                    </div>
                </div>
            @endif
        @endif
    </x-ui.modal>
</div>

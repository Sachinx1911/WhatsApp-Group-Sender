<div>
    <x-ui.modal :name="\App\Livewire\Templates\Picker::MODAL" title="Insert Template" max-width="max-w-2xl">
        <div class="relative mb-4">
            <x-lucide-search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search title, message or #tag" aria-label="Search templates"
                class="w-full rounded-xl border border-line py-2 pl-9 pr-3 text-sm outline-none focus:border-primary focus:ring-4 focus:ring-primary/10">
        </div>

        @if ($templates->isEmpty())
            <x-ui.empty-state icon="file-text" title="No templates found">
                Save frequently used messages in Message Templates.
            </x-ui.empty-state>
        @else
            <ul class="space-y-2">
                @foreach ($templates as $template)
                    <li wire:key="pick-template-{{ $template->id }}">
                        <button type="button" wire:click="choose({{ $template->id }})"
                            class="group w-full rounded-xl border border-line px-4 py-3 text-left transition hover:border-primary hover:bg-primary-soft/40">
                            <span class="flex items-center justify-between gap-3">
                                <span class="truncate text-sm font-medium group-hover:text-primary">{{ $template->title }}</span>
                                <span class="shrink-0 text-xs text-muted">{{ $template->typeLabel() }}</span>
                            </span>
                            <span class="mt-1 line-clamp-2 block text-xs text-muted">{{ \App\Support\WhatsAppFormatter::plain($template->message, 180) }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>

            @if ($templates->hasPages())
                <div class="mt-4 flex items-center justify-between text-[13px] text-muted">
                    <span>Page {{ $templates->currentPage() }} of {{ $templates->lastPage() }}</span>
                    <div class="flex gap-2">
                        <x-ui.button variant="secondary" size="sm" wire:click="previousPage('templatesPage')" :disabled="$templates->onFirstPage()">Previous</x-ui.button>
                        <x-ui.button variant="secondary" size="sm" wire:click="nextPage('templatesPage')" :disabled="! $templates->hasMorePages()">Next</x-ui.button>
                    </div>
                </div>
            @endif
        @endif
    </x-ui.modal>
</div>

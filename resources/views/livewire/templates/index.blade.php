<div>
    <x-ui.page-header title="Message Templates" subtitle="Save frequently used educational messages">
        <x-ui.button icon="plus" x-on:click="$dispatch('create-template')">Create Template</x-ui.button>
    </x-ui.page-header>

    {{-- Filters --}}
    <div class="mb-5 flex flex-wrap items-center gap-2">
        <div class="relative min-w-[240px] flex-1">
            <x-lucide-search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search title, message or #tag" aria-label="Search templates"
                class="w-full rounded-xl border border-line bg-white py-2 pl-9 pr-3 text-sm outline-none focus:border-primary focus:ring-4 focus:ring-primary/10">
        </div>
        <select wire:model.live="category" aria-label="Filter by category" class="rounded-xl border border-line bg-white py-2 pl-3 pr-8 text-sm outline-none focus:border-primary">
            <option value="">All categories</option>
            @foreach ($this->categories as $cat)
                <option value="{{ $cat->id }}">{{ $cat->name }}</option>
            @endforeach
        </select>
        <select wire:model.live="type" aria-label="Filter by type" class="rounded-xl border border-line bg-white py-2 pl-3 pr-8 text-sm outline-none focus:border-primary">
            <option value="">All types</option>
            <option value="text">Text only</option>
            <option value="image">Text + Image</option>
            <option value="pdf">Text + PDF</option>
        </select>
        <select wire:model.live="sort" aria-label="Sort templates" class="rounded-xl border border-line bg-white py-2 pl-3 pr-8 text-sm outline-none focus:border-primary">
            @foreach (\App\Livewire\Templates\Index::SORTS as $key => [$label])
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </select>
        @if ($this->hasFilters)
            <x-ui.button variant="ghost" size="sm" icon="x" wire:click="clearFilters">Clear filters</x-ui.button>
        @endif
    </div>

    @if ($templates->isEmpty())
        <x-ui.card>
            @if ($this->hasFilters)
                <x-ui.empty-state icon="search-x" title="No templates match your filters">
                    Try a different search or clear the filters.
                    <x-slot:actions>
                        <x-ui.button variant="secondary" size="sm" wire:click="clearFilters">Clear filters</x-ui.button>
                    </x-slot:actions>
                </x-ui.empty-state>
            @else
                <x-ui.empty-state icon="file-text" title="No templates yet">
                    Save messages you send often — like daily current affairs or MCQ practice — and reuse them in one click.
                    <x-slot:actions>
                        <x-ui.button size="sm" icon="plus" x-on:click="$dispatch('create-template')">Create Template</x-ui.button>
                    </x-slot:actions>
                </x-ui.empty-state>
            @endif
        </x-ui.card>
    @else
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 2xl:grid-cols-3" wire:loading.class="opacity-60" wire:target="search,category,type,sort">
            @foreach ($templates as $template)
                @php
                    [$typeIcon, $typeTone] = match ($template->attachment?->type?->value) {
                        'image' => ['image', 'bg-warning-soft text-amber-700'],
                        'pdf' => ['file-text', 'bg-danger-soft text-red-700'],
                        default => ['type', 'bg-slate-100 text-slate-600'],
                    };
                @endphp
                <article wire:key="template-{{ $template->id }}" class="flex flex-col rounded-card border border-line bg-white shadow-card transition hover:shadow-md">
                    <div class="flex items-start justify-between gap-3 px-5 pt-4">
                        <div class="min-w-0">
                            <h2 class="truncate text-[15px] font-semibold" title="{{ $template->title }}">{{ $template->title }}</h2>
                            <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                @if ($template->category)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-canvas px-2.5 py-0.5 text-xs font-medium">
                                        <span class="size-2 rounded-full" style="background-color: {{ $template->category->color ?? '#64748B' }}"></span>
                                        {{ $template->category->name }}
                                    </span>
                                @endif
                                <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium {{ $typeTone }}">
                                    <x-dynamic-component :component="'lucide-'.$typeIcon" class="size-3" /> {{ $template->typeLabel() }}
                                </span>
                            </div>
                        </div>
                        <div class="relative shrink-0" x-data="{ menu: false }" x-on:click.outside="menu = false" x-on:keydown.escape="menu = false">
                            <button type="button" x-on:click="menu = !menu" class="rounded-lg p-1.5 text-muted hover:bg-canvas hover:text-ink" aria-label="More actions for {{ $template->title }}">
                                <x-lucide-ellipsis-vertical class="size-4" />
                            </button>
                            <div x-show="menu" x-cloak x-transition.opacity
                                class="absolute right-0 top-full z-20 mt-1 w-40 rounded-xl border border-line bg-white p-1 text-[13px] shadow-lg">
                                <button type="button" x-on:click="menu = false; $dispatch('edit-template', { id: {{ $template->id }} })" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 hover:bg-canvas">
                                    <x-lucide-pencil class="size-4 text-muted" /> Edit
                                </button>
                                <button type="button" x-on:click="menu = false" wire:click="duplicate({{ $template->id }})" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 hover:bg-canvas">
                                    <x-lucide-copy class="size-4 text-muted" /> Duplicate
                                </button>
                                <button type="button" x-on:click="menu = false" wire:click="confirmDelete({{ $template->id }})" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-danger hover:bg-danger-soft">
                                    <x-lucide-trash-2 class="size-4" /> Delete
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="wa-text mx-5 mt-3 line-clamp-5 flex-1 rounded-xl bg-canvas px-3.5 py-3 text-[13px]">
                        {!! \App\Support\WhatsAppFormatter::toHtml($template->message) !!}
                    </div>

                    @if ($template->attachment)
                        <p class="mx-5 mt-2 flex items-center gap-1.5 truncate text-xs text-muted">
                            <x-lucide-paperclip class="size-3.5 shrink-0" /> <span class="truncate">{{ $template->attachment->original_name }}</span>
                        </p>
                    @endif

                    @if ($template->tags)
                        <div class="mx-5 mt-2 flex flex-wrap gap-1">
                            @foreach ($template->tags as $tag)
                                <button type="button" wire:click="$set('search', '#{{ $tag }}')" class="rounded-full bg-primary-soft px-2 py-0.5 text-[11px] font-medium text-blue-700 hover:bg-blue-100">#{{ $tag }}</button>
                            @endforeach
                        </div>
                    @endif

                    <div class="mt-4 flex items-center justify-between gap-3 border-t border-line px-5 py-3">
                        <p class="text-xs text-muted">
                            @if ($template->last_used_at)
                                Last used <span class="font-medium text-ink" title="{{ $template->last_used_at->format('d M Y, g:i A') }}">{{ $template->last_used_at->format('d M Y') }}</span>
                                · {{ $template->usage_count }} {{ Str::plural('time', $template->usage_count) }}
                            @else
                                Never used
                            @endif
                        </p>
                        <div class="flex items-center gap-1.5">
                            <x-ui.button variant="secondary" size="sm" icon="pencil" x-on:click="$dispatch('edit-template', { id: {{ $template->id }} })">Edit</x-ui.button>
                            <x-ui.button size="sm" icon="send" :href="route('send.create', ['template' => $template->id])">Use</x-ui.button>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>

        <div class="mt-5 overflow-hidden rounded-card border border-line bg-white">
            {{ $templates->links() }}
        </div>
    @endif

    {{-- Delete confirmation --}}
    <x-ui.modal :name="\App\Livewire\Templates\Index::CONFIRM_MODAL" title="Delete template?">
        <div class="flex gap-4">
            <span class="grid size-10 shrink-0 place-items-center rounded-full bg-danger-soft text-danger"><x-lucide-trash-2 class="size-5" /></span>
            <div class="text-sm">
                <p>Delete the template <b>{{ $deletingTitle }}</b>?</p>
                <p class="mt-2 text-muted">Campaigns already sent with it are not affected. Its attachment stays in the Media Library.</p>
            </div>
        </div>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button variant="danger" icon="trash-2" wire:click="delete" wire:loading.attr="disabled" wire:target="delete">Delete template</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <livewire:templates.edit-template />
</div>

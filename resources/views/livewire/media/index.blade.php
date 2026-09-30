<div>
    <x-ui.page-header title="Media Library" subtitle="Manage your educational images and PDF files">
        <x-ui.button icon="upload" x-on:click="$dispatch('upload-media')">Upload Media</x-ui.button>
    </x-ui.page-header>

    {{-- Stats --}}
    @php $stats = $this->stats; @endphp
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-ui.stat-card label="Total Files" :value="number_format($stats['total'])" icon="folder-open" tone="primary" />
        <x-ui.stat-card label="Images" :value="number_format($stats['images'])" icon="image" tone="warning" />
        <x-ui.stat-card label="PDFs" :value="number_format($stats['pdfs'])" icon="file-text" tone="danger" />
        <x-ui.stat-card label="Space Used" :value="\App\Support\StorageUsage::summary()['used_label']" icon="hard-drive" tone="accent" hint="Including thumbnails" />
    </div>

    {{-- Toolbar --}}
    <div class="mb-5 mt-6 flex flex-wrap items-center gap-2">
        <div class="relative min-w-[220px] flex-1">
            <x-lucide-search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search by file name" aria-label="Search media"
                class="w-full rounded-xl border border-line bg-white py-2 pl-9 pr-3 text-sm outline-none focus:border-primary focus:ring-4 focus:ring-primary/10">
        </div>
        <div class="flex rounded-xl border border-line bg-white p-0.5 text-[13px]" role="group" aria-label="Filter by type">
            @foreach (['' => 'All', 'image' => 'Images', 'pdf' => 'PDFs'] as $value => $label)
                <button type="button" wire:click="$set('type', '{{ $value }}')" aria-pressed="{{ $type === $value ? 'true' : 'false' }}"
                    @class(['rounded-lg px-3 py-1.5 transition', 'bg-primary text-white' => $type === $value, 'text-muted hover:text-ink' => $type !== $value])>{{ $label }}</button>
            @endforeach
        </div>
        <select wire:model.live="sort" aria-label="Sort media" class="rounded-xl border border-line bg-white py-2 pl-3 pr-8 text-sm outline-none focus:border-primary">
            @foreach (\App\Livewire\Media\Index::SORTS as $key => [$label])
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </select>
        <div class="flex rounded-xl border border-line bg-white p-0.5" role="group" aria-label="Layout">
            <button type="button" wire:click="$set('view', 'grid')" title="Grid view" aria-pressed="{{ $view === 'grid' ? 'true' : 'false' }}"
                @class(['rounded-lg p-1.5 transition', 'bg-primary text-white' => $view === 'grid', 'text-muted hover:text-ink' => $view !== 'grid'])>
                <x-lucide-layout-grid class="size-4" />
            </button>
            <button type="button" wire:click="$set('view', 'list')" title="List view" aria-pressed="{{ $view === 'list' ? 'true' : 'false' }}"
                @class(['rounded-lg p-1.5 transition', 'bg-primary text-white' => $view === 'list', 'text-muted hover:text-ink' => $view !== 'list'])>
                <x-lucide-list class="size-4" />
            </button>
        </div>
    </div>

    @if ($items->isEmpty())
        <x-ui.card>
            @if ($search !== '' || $type !== '')
                <x-ui.empty-state icon="search-x" title="No files match your search">
                    Try a different name or show all files.
                    <x-slot:actions>
                        <x-ui.button variant="secondary" size="sm" wire:click="$set('search', ''); $set('type', '')">Show all files</x-ui.button>
                    </x-slot:actions>
                </x-ui.empty-state>
            @else
                <x-ui.empty-state icon="image" title="No media yet">
                    Upload current affairs PDFs, MCQ images and notes once, then reuse them in any message.
                    <x-slot:actions>
                        <x-ui.button size="sm" icon="upload" x-on:click="$dispatch('upload-media')">Upload Media</x-ui.button>
                    </x-slot:actions>
                </x-ui.empty-state>
            @endif
        </x-ui.card>
    @elseif ($view === 'grid')
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-5" wire:loading.class="opacity-60" wire:target="search,type,sort">
            @foreach ($items as $item)
                <article wire:key="media-{{ $item->id }}" class="group flex flex-col overflow-hidden rounded-card border border-line bg-white shadow-card transition hover:shadow-md">
                    <button type="button" wire:click="preview({{ $item->id }})" class="relative block aspect-[4/3] overflow-hidden bg-canvas" aria-label="Preview {{ $item->original_name }}">
                        @if ($item->isImage())
                            <img src="{{ route('media.thumbnail', $item) }}" alt="" loading="lazy" class="absolute inset-0 size-full object-cover transition group-hover:scale-[1.02]">
                        @else
                            <span class="grid size-full place-items-center">
                                <span class="relative grid h-20 w-16 place-items-center rounded-lg border border-red-200 bg-white shadow-sm">
                                    <x-lucide-file-text class="size-7 text-danger" />
                                    <span class="absolute -bottom-2 rounded bg-danger px-1.5 text-[10px] font-bold text-white">PDF</span>
                                </span>
                            </span>
                        @endif
                        <span class="absolute inset-0 grid place-items-center bg-navy/0 opacity-0 transition group-hover:bg-navy/30 group-hover:opacity-100">
                            <span class="flex items-center gap-1.5 rounded-full bg-white px-3 py-1.5 text-xs font-medium shadow"><x-lucide-eye class="size-3.5" /> Preview</span>
                        </span>
                    </button>

                    <div class="flex flex-1 flex-col px-3.5 pb-3 pt-2.5">
                        <p class="truncate text-[13px] font-medium" title="{{ $item->original_name }}">{{ $item->original_name }}</p>
                        <p class="mt-0.5 text-xs text-muted">{{ $item->type->label() }} · {{ $item->humanSize() }}</p>
                        <div class="mt-2 flex items-center justify-between text-[11px] text-muted">
                            <span title="{{ $item->created_at->format('d M Y, g:i A') }}">{{ $item->created_at->format('d M Y') }}</span>
                            <span class="inline-flex items-center gap-1"><x-lucide-send class="size-3" /> Used {{ $item->usage_count }}×</span>
                        </div>
                        <div class="mt-3 flex items-center gap-1 border-t border-line pt-2.5">
                            <x-ui.button size="sm" icon="send" :href="route('send.create', ['media' => $item->id])" class="flex-1">Use</x-ui.button>
                            <button type="button" wire:click="startRename({{ $item->id }})" class="rounded-lg p-1.5 text-muted hover:bg-canvas hover:text-ink" title="Rename" aria-label="Rename {{ $item->original_name }}">
                                <x-lucide-pencil class="size-4" />
                            </button>
                            <a href="{{ route('media.download', $item) }}" class="rounded-lg p-1.5 text-muted hover:bg-canvas hover:text-ink" title="Download" aria-label="Download {{ $item->original_name }}">
                                <x-lucide-download class="size-4" />
                            </a>
                            <button type="button" wire:click="confirmDelete({{ $item->id }})" class="rounded-lg p-1.5 text-muted hover:bg-danger-soft hover:text-danger" title="Delete" aria-label="Delete {{ $item->original_name }}">
                                <x-lucide-trash-2 class="size-4" />
                            </button>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    @else
        <x-ui.card :padding="false">
            <div class="overflow-x-auto" wire:loading.class="opacity-60" wire:target="search,type,sort">
                <table class="w-full min-w-[760px] text-left text-[13px]">
                    <thead class="bg-canvas text-xs text-muted">
                        <tr>
                            <th class="px-5 py-2.5 font-medium">File</th>
                            <th class="px-3 py-2.5 font-medium">Type</th>
                            <th class="px-3 py-2.5 text-right font-medium">Size</th>
                            <th class="px-3 py-2.5 font-medium">Uploaded</th>
                            <th class="px-3 py-2.5 text-right font-medium">Used</th>
                            <th class="px-5 py-2.5 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($items as $item)
                            <tr wire:key="media-row-{{ $item->id }}" class="transition hover:bg-canvas/60">
                                <td class="px-5 py-2.5">
                                    <button type="button" wire:click="preview({{ $item->id }})" class="flex items-center gap-3 text-left hover:text-primary">
                                        @if ($item->isImage())
                                            <img src="{{ route('media.thumbnail', $item) }}" alt="" loading="lazy" class="size-10 rounded-lg object-cover">
                                        @else
                                            <span class="grid h-10 w-10 place-items-center rounded-lg bg-danger-soft text-[10px] font-bold text-danger">PDF</span>
                                        @endif
                                        <span class="max-w-[320px] truncate font-medium">{{ $item->original_name }}</span>
                                    </button>
                                </td>
                                <td class="px-3 py-2.5"><x-ui.badge :color="$item->isImage() ? 'warning' : 'danger'">{{ $item->type->label() }}</x-ui.badge></td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ $item->humanSize() }}</td>
                                <td class="whitespace-nowrap px-3 py-2.5 text-muted">{{ $item->created_at->format('d M Y, g:i A') }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ $item->usage_count }}</td>
                                <td class="px-5 py-2.5">
                                    <div class="flex items-center justify-end gap-0.5 text-muted">
                                        <button type="button" wire:click="preview({{ $item->id }})" class="rounded-lg p-1.5 hover:bg-canvas hover:text-ink" title="Preview"><x-lucide-eye class="size-4" /></button>
                                        <a href="{{ route('send.create', ['media' => $item->id]) }}" class="rounded-lg p-1.5 hover:bg-canvas hover:text-ink" title="Use in Message"><x-lucide-send class="size-4" /></a>
                                        <button type="button" wire:click="startRename({{ $item->id }})" class="rounded-lg p-1.5 hover:bg-canvas hover:text-ink" title="Rename"><x-lucide-pencil class="size-4" /></button>
                                        <a href="{{ route('media.download', $item) }}" class="rounded-lg p-1.5 hover:bg-canvas hover:text-ink" title="Download"><x-lucide-download class="size-4" /></a>
                                        <button type="button" wire:click="confirmDelete({{ $item->id }})" class="rounded-lg p-1.5 hover:bg-danger-soft hover:text-danger" title="Delete"><x-lucide-trash-2 class="size-4" /></button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif

    @if ($items->isNotEmpty())
        <div class="mt-5 overflow-hidden rounded-card border border-line bg-white">
            {{ $items->links() }}
        </div>
    @endif

    {{-- Preview --}}
    @php $active = $this->active; @endphp
    <x-ui.modal :name="\App\Livewire\Media\Index::PREVIEW_MODAL" :title="$active?->original_name ?? 'Preview'" max-width="max-w-5xl">
        @if ($active)
            <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_260px]">
                <div class="grid min-h-[320px] place-items-center overflow-hidden rounded-xl bg-canvas">
                    @if ($active->isImage())
                        <img src="{{ route('media.file', $active) }}" alt="{{ $active->original_name }}" class="max-h-[65vh] w-auto object-contain">
                    @else
                        <iframe src="{{ route('media.file', $active) }}" title="{{ $active->original_name }}" class="h-[65vh] w-full border-0 bg-white"></iframe>
                    @endif
                </div>
                <div>
                    <dl class="divide-y divide-line text-[13px]">
                        @foreach ([
                            ['Type', $active->type->label()],
                            ['Size', $active->humanSize()],
                            ['Uploaded', $active->created_at->format('d M Y, g:i A')],
                            ['Used in campaigns', $active->usage_count.' '.Str::plural('time', $active->usage_count)],
                            ['Default for templates', $active->templates_count],
                        ] as [$label, $value])
                            <div class="flex justify-between gap-3 py-2.5 first:pt-0">
                                <dt class="text-muted">{{ $label }}</dt>
                                <dd class="text-right font-medium">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    <div class="mt-4 grid gap-2">
                        <x-ui.button icon="send" :href="route('send.create', ['media' => $active->id])">Use in Message</x-ui.button>
                        <x-ui.button variant="secondary" icon="download" :href="route('media.download', $active)">Download</x-ui.button>
                        <x-ui.button variant="secondary" icon="pencil" wire:click="startRename({{ $active->id }})" x-on:click="open = false">Rename</x-ui.button>
                        <x-ui.button variant="ghost" icon="trash-2" class="text-danger hover:bg-danger-soft hover:text-danger" wire:click="confirmDelete({{ $active->id }})">Delete</x-ui.button>
                    </div>
                </div>
            </div>
        @endif
    </x-ui.modal>

    {{-- Rename --}}
    <x-ui.modal :name="\App\Livewire\Media\Index::RENAME_MODAL" title="Rename file">
        <form wire:submit="rename" id="rename-media-form">
            <label for="rename-base" class="mb-1.5 block text-[13px] font-medium">File name</label>
            <div class="flex items-center overflow-hidden rounded-xl border border-line focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/10">
                <input wire:model="renameBase" id="rename-base" type="text" autocomplete="off" class="min-w-0 flex-1 border-0 px-3.5 py-2.5 text-sm outline-none">
                <span class="border-l border-line bg-canvas px-3 py-2.5 text-sm text-muted">.{{ $active ? pathinfo($active->original_name, PATHINFO_EXTENSION) : '' }}</span>
            </div>
            @error('renameBase') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
            <p class="mt-2 text-xs text-muted">Only the name shown in the app changes. WhatsApp receives the file with this name.</p>
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button type="submit" form="rename-media-form" wire:loading.attr="disabled" wire:target="rename">Save name</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- Delete --}}
    <x-ui.modal :name="\App\Livewire\Media\Index::DELETE_MODAL" title="Delete file?">
        <div class="flex gap-4">
            <span class="grid size-10 shrink-0 place-items-center rounded-full bg-danger-soft text-danger"><x-lucide-trash-2 class="size-5" /></span>
            <div class="text-sm">
                <p>Delete <b class="break-all">{{ $activeName }}</b> permanently?</p>
                @if ($active?->templates_count)
                    <p class="mt-2 text-amber-700">It is the default attachment of {{ $active->templates_count }} {{ Str::plural('template', $active->templates_count) }}. They will keep their text but lose this attachment.</p>
                @endif
                <p class="mt-2 text-muted">Past send history keeps the file name. This cannot be undone.</p>
            </div>
        </div>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button variant="danger" icon="trash-2" wire:click="delete" wire:loading.attr="disabled" wire:target="delete">Delete file</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <livewire:media.uploader />
</div>

<div>
    <x-ui.page-header title="Group Manager" subtitle="Manage your WhatsApp student groups">
        <x-ui.button variant="secondary" icon="tags" x-on:click="$dispatch('open-modal', '{{ \App\Livewire\Categories\Manager::MODAL }}')">Categories</x-ui.button>
        <x-ui.button variant="secondary" icon="upload" x-on:click="$dispatch('import-groups')">Import Groups</x-ui.button>
        <x-ui.button variant="secondary" icon="download" :href="route('groups.export', array_filter($this->filters()))">Export</x-ui.button>
        <x-ui.button
            variant="secondary"
            icon="refresh-cw"
            wire:click="syncFromWhatsApp"
            wire:loading.attr="disabled"
            wire:target="syncFromWhatsApp"
            :disabled="! $this->canSyncFromWhatsApp"
            :title="$this->canSyncFromWhatsApp ? 'Import new chats from the linked WhatsApp account' : 'Connect WhatsApp (Settings → WhatsApp Connection) with the Playwright worker running to enable this'"
        >Sync from WhatsApp</x-ui.button>
        <x-ui.button icon="plus" x-on:click="$dispatch('create-group')">Add Group</x-ui.button>
    </x-ui.page-header>

    {{-- Stats --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-ui.stat-card label="Total Groups" :value="number_format($this->stats['total'])" icon="users" tone="primary" />
        <x-ui.stat-card label="Active" :value="number_format($this->stats['active'])" icon="circle-check" tone="success" hint="Available for sending" />
        <x-ui.stat-card label="Inactive" :value="number_format($this->stats['inactive'])" icon="circle-pause" tone="muted" hint="Hidden when selecting groups" />
    </div>

    <x-ui.card :padding="false" class="mt-6">
        {{-- Filters --}}
        <div class="flex flex-wrap items-center gap-2 border-b border-line p-4">
            <div class="relative min-w-[220px] flex-1">
                <x-lucide-search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
                <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search by group name or category" aria-label="Search groups"
                    class="w-full rounded-xl border border-line bg-white py-2 pl-9 pr-3 text-sm outline-none focus:border-primary focus:ring-4 focus:ring-primary/10">
            </div>

            <select wire:model.live="category" aria-label="Filter by category"
                class="rounded-xl border border-line bg-white py-2 pl-3 pr-8 text-sm outline-none focus:border-primary">
                <option value="">All categories</option>
                @foreach ($this->categories as $cat)
                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                @endforeach
            </select>

            <select wire:model.live="status" aria-label="Filter by status"
                class="rounded-xl border border-line bg-white py-2 pl-3 pr-8 text-sm outline-none focus:border-primary">
                <option value="">All statuses</option>
                @foreach (\App\Enums\GroupStatus::cases() as $groupStatus)
                    <option value="{{ $groupStatus->value }}">{{ $groupStatus->label() }}</option>
                @endforeach
            </select>

            <div class="flex items-center gap-1.5 rounded-xl border border-line bg-white px-3 py-1 text-sm">
                <x-lucide-users class="size-4 text-muted" />
                <input wire:model.live.debounce.400ms="minMembers" type="number" min="0" placeholder="Min" aria-label="Minimum members"
                    class="w-14 border-0 bg-transparent py-1 text-sm outline-none [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none">
                <span class="text-muted">–</span>
                <input wire:model.live.debounce.400ms="maxMembers" type="number" min="0" placeholder="Max" aria-label="Maximum members"
                    class="w-14 border-0 bg-transparent py-1 text-sm outline-none [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none">
            </div>

            @if ($this->hasFilters)
                <x-ui.button variant="ghost" size="sm" icon="x" wire:click="clearFilters">Clear filters</x-ui.button>
            @endif
        </div>

        {{-- Selection / bulk actions --}}
        <div x-data x-show="$wire.selectAll || $wire.selected.length" x-cloak
            class="flex flex-wrap items-center gap-2 border-b border-line bg-primary-soft/60 px-4 py-2.5 text-[13px]">
            <span class="font-medium text-blue-800">
                <span x-text="$wire.selectAll ? {{ $groups->total() }} : $wire.selected.length"></span> selected
            </span>

            @if (! $selectAll && $groups->total() > $groups->count())
                <button type="button" wire:click="$set('selectAll', true)" x-show="$wire.selected.length >= {{ $groups->count() }}"
                    class="text-primary hover:underline">Select all {{ $groups->total() }} matching groups</button>
            @endif

            <div class="ml-auto flex flex-wrap items-center gap-2">
                <x-ui.button variant="secondary" size="sm" icon="circle-check" wire:click="bulkSetStatus('active')">Activate</x-ui.button>
                <x-ui.button variant="secondary" size="sm" icon="circle-pause" wire:click="bulkSetStatus('inactive')">Deactivate</x-ui.button>
                <div class="flex items-center gap-1">
                    <select wire:model="bulkCategory" aria-label="Move to category"
                        class="rounded-lg border border-line bg-white py-1.5 pl-2.5 pr-7 text-[13px] outline-none focus:border-primary">
                        <option value="">Move to category…</option>
                        @foreach ($this->categories as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                        @endforeach
                    </select>
                    <x-ui.button variant="secondary" size="sm" wire:click="bulkChangeCategory">Move</x-ui.button>
                </div>
                <x-ui.button variant="danger" size="sm" icon="trash-2" wire:click="confirmDelete">Delete</x-ui.button>
                <button type="button" wire:click="clearSelection" class="px-1 text-muted hover:text-ink">Clear</button>
            </div>
        </div>

        {{-- Table --}}
        @if ($groups->isEmpty())
            @if ($this->hasFilters)
                <x-ui.empty-state icon="search-x" title="No groups match your filters">
                    Try a different search or clear the filters.
                    <x-slot:actions>
                        <x-ui.button variant="secondary" size="sm" wire:click="clearFilters">Clear filters</x-ui.button>
                    </x-slot:actions>
                </x-ui.empty-state>
            @else
                <x-ui.empty-state icon="users" title="No groups yet">
                    Add your WhatsApp student groups one by one, or import them from a CSV file.
                    <x-slot:actions>
                        <x-ui.button size="sm" icon="plus" x-on:click="$dispatch('create-group')">Add Group</x-ui.button>
                        <x-ui.button size="sm" variant="secondary" icon="upload" x-on:click="$dispatch('import-groups')">Import Groups</x-ui.button>
                    </x-slot:actions>
                </x-ui.empty-state>
            @endif
        @else
            <div class="overflow-x-auto" wire:loading.class="opacity-60" wire:target="search,category,status,minMembers,maxMembers,sortBy,gotoPage,nextPage,previousPage,perPage">
                <table class="w-full min-w-[860px] text-left text-[13px]">
                    <thead class="bg-canvas text-xs text-muted">
                        <tr>
                            <th class="w-10 py-2.5 pl-5 pr-2">
                                <input type="checkbox" aria-label="Select all groups on this page" class="size-4 accent-primary"
                                    x-data="{ ids: @js($groups->pluck('id')->map(fn ($id) => (string) $id)->all()) }"
                                    x-bind:checked="$wire.selectAll || ids.every(id => $wire.selected.includes(id))"
                                    x-on:change="$wire.selectAll = false; $wire.selected = $event.target.checked
                                        ? [...new Set([...$wire.selected, ...ids])]
                                        : $wire.selected.filter(id => ! ids.includes(id))">
                            </th>
                            <x-ui.sort-header column="name" :sort-by="$sortBy" :sort-dir="$sortDir">Group Name</x-ui.sort-header>
                            <th class="px-3 py-2.5 font-medium">Category</th>
                            <x-ui.sort-header column="member_count" :sort-by="$sortBy" :sort-dir="$sortDir" align="right">Members</x-ui.sort-header>
                            <th class="px-3 py-2.5 font-medium">Status</th>
                            <x-ui.sort-header column="last_sent_at" :sort-by="$sortBy" :sort-dir="$sortDir">Last Sent</x-ui.sort-header>
                            <x-ui.sort-header column="created_at" :sort-by="$sortBy" :sort-dir="$sortDir">Created</x-ui.sort-header>
                            <th class="px-5 py-2.5 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($groups as $group)
                            <tr wire:key="group-{{ $group->id }}" class="transition hover:bg-canvas/60"
                                x-data x-bind:class="($wire.selectAll || $wire.selected.includes('{{ $group->id }}')) && 'bg-primary-soft/40'">
                                <td class="py-2.5 pl-5 pr-2">
                                    <input type="checkbox" wire:model="selected" value="{{ $group->id }}" x-on:change="$wire.selectAll = false"
                                        x-bind:checked="$wire.selectAll || $wire.selected.includes('{{ $group->id }}')"
                                        aria-label="Select {{ $group->name }}" class="size-4 accent-primary">
                                </td>
                                <td class="px-3 py-2.5">
                                    <a href="{{ route('groups.show', $group) }}" class="flex items-center gap-3 font-medium hover:text-primary">
                                        <span class="grid size-8 shrink-0 place-items-center rounded-lg text-white"
                                            style="background-color: {{ $group->category->color ?? '#64748B' }}">
                                            <x-lucide-users class="size-4" />
                                        </span>
                                        <span class="truncate">{{ $group->name }}</span>
                                    </a>
                                </td>
                                <td class="px-3 py-2.5">
                                    <span class="inline-flex items-center gap-1.5">
                                        <span class="size-2 rounded-full" style="background-color: {{ $group->category->color ?? '#64748B' }}"></span>
                                        {{ $group->category->name }}
                                    </span>
                                </td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ $group->member_count !== null ? number_format($group->member_count) : '—' }}</td>
                                <td class="px-3 py-2.5"><x-ui.status-badge :status="$group->status" /></td>
                                <td class="whitespace-nowrap px-3 py-2.5 text-muted" @if ($group->last_sent_at) title="{{ $group->last_sent_at->format('d M Y, g:i A') }}" @endif>
                                    {{ $group->last_sent_at?->diffForHumans() ?? 'Never' }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-2.5 text-muted">{{ $group->created_at->format('d M Y') }}</td>
                                <td class="px-5 py-2.5">
                                    <div class="flex items-center justify-end gap-0.5 text-muted">
                                        <a href="{{ route('groups.show', $group) }}" class="rounded-lg p-1.5 hover:bg-canvas hover:text-ink" title="View" aria-label="View {{ $group->name }}">
                                            <x-lucide-eye class="size-4" />
                                        </a>
                                        <button type="button" x-on:click="$dispatch('edit-group', { id: {{ $group->id }} })" class="rounded-lg p-1.5 hover:bg-canvas hover:text-ink" title="Edit" aria-label="Edit {{ $group->name }}">
                                            <x-lucide-pencil class="size-4" />
                                        </button>
                                        <button type="button" wire:click="toggleStatus({{ $group->id }})" class="rounded-lg p-1.5 hover:bg-canvas hover:text-ink"
                                            title="{{ $group->isActive() ? 'Deactivate' : 'Activate' }}" aria-label="{{ $group->isActive() ? 'Deactivate' : 'Activate' }} {{ $group->name }}">
                                            @if ($group->isActive())
                                                <x-lucide-circle-pause class="size-4" />
                                            @else
                                                <x-lucide-circle-play class="size-4" />
                                            @endif
                                        </button>
                                        <button type="button" wire:click="confirmDelete({{ $group->id }})" class="rounded-lg p-1.5 hover:bg-danger-soft hover:text-danger" title="Delete" aria-label="Delete {{ $group->name }}">
                                            <x-lucide-trash-2 class="size-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{ $groups->links() }}
    </x-ui.card>

    {{-- Delete confirmation --}}
    @php $deletingCount = count($deleting); @endphp
    <x-ui.modal :name="\App\Livewire\Groups\Index::CONFIRM_MODAL" :title="$deletingCount === 1 ? 'Delete group?' : 'Delete groups?'">
        <div class="flex gap-4">
            <span class="grid size-10 shrink-0 place-items-center rounded-full bg-danger-soft text-danger">
                <x-lucide-trash-2 class="size-5" />
            </span>
            <div class="text-sm">
                <p>
                    You are about to delete <b>{{ $deletingCount }} {{ Str::plural('group', $deletingCount) }}</b>:
                    {{ $this->deletingGroups->join(', ') }}{{ $deletingCount > 5 ? ' and '.($deletingCount - 5).' more' : '' }}.
                </p>
                <p class="mt-2 text-muted">The groups are removed from this app only — nothing changes in WhatsApp. Past send history is kept.</p>
            </div>
        </div>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button variant="danger" icon="trash-2" wire:click="delete" wire:loading.attr="disabled" wire:target="delete">
                Delete {{ $deletingCount }} {{ Str::plural('group', $deletingCount) }}
            </x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <livewire:groups.edit-group />
    <livewire:groups.import-groups />
    <livewire:categories.manager />
</div>

<div>
    <x-ui.modal :name="\App\Livewire\Categories\Manager::MODAL" title="Manage Categories" max-width="max-w-xl">
        <p class="-mt-1 mb-4 text-[13px] text-muted">Categories organise your batches. A category that still has groups cannot be deleted.</p>

        <ul class="divide-y divide-line rounded-xl border border-line">
            @foreach ($this->categories as $category)
                <li wire:key="category-{{ $category->id }}" class="px-3 py-2.5">
                    @if ($editingId === $category->id)
                        <form wire:submit="update" class="space-y-2.5">
                            <div class="flex items-center gap-2">
                                <input wire:model="editName" type="text" aria-label="Category name" autofocus
                                    class="min-w-0 flex-1 rounded-lg border border-line px-3 py-1.5 text-sm outline-none focus:border-primary focus:ring-4 focus:ring-primary/10">
                                <x-ui.button type="submit" size="sm">Save</x-ui.button>
                                <x-ui.button variant="ghost" size="sm" wire:click="cancelEdit">Cancel</x-ui.button>
                            </div>
                            @include('livewire.categories.partials.color-picker', ['model' => 'editColor', 'selected' => $editColor])
                            @error('editName') <p class="text-xs text-danger">{{ $message }}</p> @enderror
                        </form>
                    @else
                        <div class="flex items-center gap-3">
                            <span class="size-3 shrink-0 rounded-full" style="background-color: {{ $category->color ?? '#64748B' }}"></span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium">{{ $category->name }}</span>
                                <span class="block text-xs text-muted">{{ $category->groups_count }} {{ Str::plural('group', $category->groups_count) }}</span>
                            </span>

                            <div class="flex items-center gap-0.5 text-muted">
                                <button type="button" wire:click="move({{ $category->id }}, 'up')" @disabled($loop->first)
                                    class="rounded-lg p-1.5 hover:bg-canvas hover:text-ink disabled:opacity-30 disabled:hover:bg-transparent" aria-label="Move {{ $category->name }} up">
                                    <x-lucide-arrow-up class="size-4" />
                                </button>
                                <button type="button" wire:click="move({{ $category->id }}, 'down')" @disabled($loop->last)
                                    class="rounded-lg p-1.5 hover:bg-canvas hover:text-ink disabled:opacity-30 disabled:hover:bg-transparent" aria-label="Move {{ $category->name }} down">
                                    <x-lucide-arrow-down class="size-4" />
                                </button>
                                <button type="button" wire:click="edit({{ $category->id }})"
                                    class="rounded-lg p-1.5 hover:bg-canvas hover:text-ink" aria-label="Rename {{ $category->name }}">
                                    <x-lucide-pencil class="size-4" />
                                </button>
                                <button type="button"
                                    @if ($category->groups_count)
                                        disabled title="Move its {{ $category->groups_count }} {{ Str::plural('group', $category->groups_count) }} to another category first"
                                    @else
                                        wire:click="delete({{ $category->id }})" wire:confirm="Delete the category “{{ $category->name }}”?"
                                    @endif
                                    class="rounded-lg p-1.5 hover:bg-danger-soft hover:text-danger disabled:cursor-not-allowed disabled:opacity-30 disabled:hover:bg-transparent disabled:hover:text-muted"
                                    aria-label="Delete {{ $category->name }}">
                                    <x-lucide-trash-2 class="size-4" />
                                </button>
                            </div>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>

        <form wire:submit="add" class="mt-5 rounded-xl bg-canvas p-4">
            <p class="mb-2 text-[13px] font-medium">Add a category</p>
            <div class="flex gap-2">
                <input wire:model="newName" type="text" placeholder="e.g. Talathi Bharti" aria-label="New category name"
                    class="min-w-0 flex-1 rounded-xl border border-line bg-white px-3.5 py-2 text-sm outline-none focus:border-primary focus:ring-4 focus:ring-primary/10">
                <x-ui.button type="submit" icon="plus" wire:loading.attr="disabled" wire:target="add">Add</x-ui.button>
            </div>
            <div class="mt-2.5">
                @include('livewire.categories.partials.color-picker', ['model' => 'newColor', 'selected' => $newColor])
            </div>
            @error('newName') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Done</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>

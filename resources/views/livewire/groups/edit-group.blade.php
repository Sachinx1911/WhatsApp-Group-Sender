<div>
    <x-ui.modal :name="\App\Livewire\Groups\EditGroup::MODAL" :title="$form->group ? 'Edit Group' : 'Add Group'">
        <form wire:submit="save" id="group-form" class="space-y-4" novalidate>
            <div>
                <label for="group-name" class="mb-1.5 block text-[13px] font-medium">Group name</label>
                <input wire:model="form.name" id="group-name" type="text" autocomplete="off" placeholder="e.g. MPSC Batch 01"
                    @class(['w-full rounded-xl border bg-white px-3.5 py-2.5 outline-none transition focus:border-primary focus:ring-4 focus:ring-primary/10',
                        'border-danger' => $errors->has('form.name'), 'border-line' => ! $errors->has('form.name')])>
                @error('form.name')
                    <p class="mt-1.5 text-xs text-danger">{{ $message }}</p>
                @else
                    <p class="mt-1.5 flex items-start gap-1.5 text-xs text-amber-700">
                        <x-lucide-info class="mt-px size-3.5 shrink-0" />
                        Must match the WhatsApp group name exactly, including spaces and capital letters. WhatsApp Web finds groups by name.
                    </p>
                @enderror
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="group-category" class="mb-1.5 block text-[13px] font-medium">Category</label>
                    <select wire:model="form.category_id" id="group-category"
                        class="w-full rounded-xl border border-line bg-white px-3 py-2.5 outline-none focus:border-primary focus:ring-4 focus:ring-primary/10">
                        @foreach ($this->categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                    @error('form.category_id') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="group-members" class="mb-1.5 block text-[13px] font-medium">Members <span class="font-normal text-muted">(optional)</span></label>
                    <input wire:model="form.member_count" id="group-members" type="number" min="0" max="{{ \App\Livewire\Forms\GroupForm::MAX_MEMBERS }}" placeholder="e.g. 250"
                        class="w-full rounded-xl border border-line bg-white px-3.5 py-2.5 outline-none focus:border-primary focus:ring-4 focus:ring-primary/10">
                    @error('form.member_count') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label for="group-identifier" class="mb-1.5 block text-[13px] font-medium">WhatsApp group identifier <span class="font-normal text-muted">(optional)</span></label>
                <input wire:model="form.whatsapp_identifier" id="group-identifier" type="text" autocomplete="off"
                    placeholder="Filled automatically by Sync from WhatsApp"
                    class="w-full rounded-xl border border-line bg-white px-3.5 py-2.5 outline-none focus:border-primary focus:ring-4 focus:ring-primary/10">
                @error('form.whatsapp_identifier') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
            </div>

            <fieldset>
                <legend class="mb-1.5 text-[13px] font-medium">Status</legend>
                <div class="grid grid-cols-2 gap-2">
                    @foreach (\App\Enums\GroupStatus::cases() as $status)
                        <label class="flex cursor-pointer items-center gap-2.5 rounded-xl border border-line px-3.5 py-2.5 text-sm has-checked:border-primary has-checked:bg-primary-soft">
                            <input wire:model="form.status" type="radio" value="{{ $status->value }}" class="accent-primary">
                            {{ $status->label() }}
                        </label>
                    @endforeach
                </div>
                <p class="mt-1.5 text-xs text-muted">Inactive groups are hidden when selecting groups to send to.</p>
            </fieldset>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button type="submit" form="group-form" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">{{ $form->group ? 'Save changes' : 'Add group' }}</span>
                <span wire:loading wire:target="save">Saving...</span>
            </x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>

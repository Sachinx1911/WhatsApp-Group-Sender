<div>
    <x-ui.modal :name="\App\Livewire\Templates\EditTemplate::MODAL" :title="$form->template ? 'Edit Template' : 'Create Template'" max-width="max-w-5xl">
        <div x-data="messageEditor($wire.entangle('form.message'))" class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
            <form wire:submit="save" id="template-form" class="space-y-4" novalidate>
                <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_200px]">
                    <div>
                        <label for="template-title" class="mb-1.5 block text-[13px] font-medium">Title</label>
                        <input wire:model="form.title" id="template-title" type="text" placeholder="e.g. Today's Current Affairs" autocomplete="off"
                            @class(['w-full rounded-xl border bg-white px-3.5 py-2.5 outline-none transition focus:border-primary focus:ring-4 focus:ring-primary/10',
                                'border-danger' => $errors->has('form.title'), 'border-line' => ! $errors->has('form.title')])>
                        @error('form.title') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="template-category" class="mb-1.5 block text-[13px] font-medium">Category</label>
                        <select wire:model="form.category_id" id="template-category"
                            class="w-full rounded-xl border border-line bg-white px-3 py-2.5 outline-none focus:border-primary focus:ring-4 focus:ring-primary/10">
                            <option value="">No category</option>
                            @foreach ($this->categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                {{-- Message --}}
                <div>
                    <div class="mb-1.5 flex items-end justify-between">
                        <label for="template-message" class="text-[13px] font-medium">Message</label>
                        @if (config('educationhub.message.show_counter'))
                        <span class="text-xs text-muted" :class="length > {{ \App\Support\WhatsAppFormatter::MAX_LENGTH }} && 'font-medium text-danger'">
                            <span x-text="length.toLocaleString()"></span> / {{ number_format(\App\Support\WhatsAppFormatter::MAX_LENGTH) }}
                        </span>
                        @endif
                    </div>
                    <div @class(['overflow-hidden rounded-xl border bg-white transition focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/10',
                        'border-danger' => $errors->has('form.message'), 'border-line' => ! $errors->has('form.message')])>
                        <div class="flex flex-wrap items-center gap-0.5 border-b border-line bg-canvas px-2 py-1.5">
                            @foreach ([['*', 'bold', 'Bold'], ['_', 'italic', 'Italic'], ['~', 'strikethrough', 'Strikethrough'], ['```', 'code', 'Monospace']] as [$marker, $icon, $label])
                                <button type="button" x-on:click="wrap(@js($marker))" title="{{ $label }} ({{ $marker }}text{{ $marker }})" aria-label="{{ $label }}"
                                    class="grid size-8 place-items-center rounded-lg text-muted transition hover:bg-white hover:text-ink">
                                    <x-dynamic-component :component="'lucide-'.$icon" class="size-4" />
                                </button>
                            @endforeach
                            <span class="mx-1 h-5 w-px bg-line"></span>
                            <div class="relative" x-data="{ emoji: false }" x-on:click.outside="emoji = false">
                                <button type="button" x-on:click="emoji = !emoji" title="Insert emoji" aria-label="Insert emoji"
                                    class="grid size-8 place-items-center rounded-lg text-muted transition hover:bg-white hover:text-ink">
                                    <x-lucide-smile class="size-4" />
                                </button>
                                <div x-show="emoji" x-cloak x-transition.opacity
                                    class="absolute left-0 top-full z-10 mt-1 grid w-64 grid-cols-8 gap-0.5 rounded-xl border border-line bg-white p-2 shadow-lg">
                                    @foreach (['📚', '📰', '📝', '✅', '❌', '📢', '🙏', '⏰', '🎯', '🏆', '📌', '👉', '🔥', '⭐', '📖', '✍️', '🎓', '👮', '📅', '📊', '💡', '❓', '👍', '🎉'] as $emoji)
                                        <button type="button" x-on:click="insert(@js($emoji)); emoji = false"
                                            class="grid size-7 place-items-center rounded-md text-lg hover:bg-canvas">{{ $emoji }}</button>
                                    @endforeach
                                </div>
                            </div>
                            <span class="ml-auto hidden text-[11px] text-muted sm:block">Marathi, English & emoji supported</span>
                        </div>
                        <textarea wire:ignore x-ref="textarea" x-model="text" id="template-message" rows="10" placeholder="Write your message here..."
                            class="block w-full resize-y border-0 px-3.5 py-3 text-sm leading-relaxed outline-none"></textarea>
                    </div>
                    @error('form.message') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                </div>

                {{-- Default attachments --}}
                <div>
                    <p class="mb-1.5 text-[13px] font-medium">Default attachments <span class="font-normal text-muted">(optional, up to {{ \App\Livewire\Forms\TemplateForm::MAX_ATTACHMENTS }})</span></p>
                    @if ($this->attachments->isNotEmpty())
                        <ul class="space-y-2">
                            @foreach ($this->attachments as $file)
                                <li wire:key="tpl-file-{{ $file->id }}" class="flex items-center gap-3 rounded-xl border border-line px-3 py-2.5">
                                    @if ($file->isImage())
                                        <img src="{{ route('media.thumbnail', $file) }}" alt="" class="size-10 rounded-lg object-cover">
                                    @else
                                        <span class="grid h-10 w-9 place-items-center rounded-md bg-red-500 text-[10px] font-bold text-white">PDF</span>
                                    @endif
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-[13px] font-medium">{{ $file->original_name }}</span>
                                        <span class="block text-xs text-muted">{{ $file->type->label() }} · {{ $file->humanSize() }}</span>
                                    </span>
                                    <button type="button" wire:click="removeAttachment({{ $file->id }})" class="rounded-lg p-1.5 text-muted hover:bg-danger-soft hover:text-danger" aria-label="Remove {{ $file->original_name }}">
                                        <x-lucide-x class="size-4" />
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                        @if ($this->attachments->count() < \App\Livewire\Forms\TemplateForm::MAX_ATTACHMENTS)
                            <button type="button" x-on:click="$dispatch('pick-media', { context: '{{ \App\Livewire\Templates\EditTemplate::PICKER_CONTEXT }}' })"
                                class="mt-2 flex w-full items-center justify-center gap-2 rounded-xl border border-dashed border-line px-4 py-2.5 text-[13px] text-muted transition hover:border-primary hover:text-primary">
                                <x-lucide-plus class="size-4" /> Add another image or PDF
                            </button>
                        @endif
                    @else
                        <button type="button" x-on:click="$dispatch('pick-media', { context: '{{ \App\Livewire\Templates\EditTemplate::PICKER_CONTEXT }}' })"
                            class="flex w-full items-center justify-center gap-2 rounded-xl border border-dashed border-line px-4 py-3 text-[13px] text-muted transition hover:border-primary hover:text-primary">
                            <x-lucide-paperclip class="size-4" /> Choose images or PDFs from the Media Library
                        </button>
                    @endif
                    @error('form.attachment_id') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                    @error('form.attachment_ids') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                </div>

                {{-- Tags --}}
                <div>
                    <label for="template-tags" class="mb-1.5 block text-[13px] font-medium">Tags <span class="font-normal text-muted">(optional)</span></label>
                    <div class="flex flex-wrap items-center gap-1.5 rounded-xl border border-line bg-white px-2.5 py-2 focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/10">
                        @foreach ($form->tags as $tag)
                            <span wire:key="tag-{{ $tag }}" class="inline-flex items-center gap-1 rounded-full bg-primary-soft py-0.5 pl-2.5 pr-1 text-xs font-medium text-blue-700">
                                #{{ $tag }}
                                <button type="button" wire:click="removeTag(@js($tag))" class="rounded-full p-0.5 hover:bg-blue-200" aria-label="Remove tag {{ $tag }}">
                                    <x-lucide-x class="size-3" />
                                </button>
                            </span>
                        @endforeach
                        {{-- The typed text stays in the browser until it becomes a tag, so a re-render never clears it mid-typing. --}}
                        <input wire:ignore id="template-tags" type="text" placeholder="Add tag, e.g. current-affairs"
                            x-data="{ tag: '', add() { if (this.tag.trim()) { $wire.addTag(this.tag); this.tag = '' } } }"
                            x-model="tag"
                            x-on:keydown.enter.prevent="add()"
                            x-on:input="if (tag.includes(',')) add()"
                            x-on:blur="add()"
                            x-on:keydown.backspace="if (tag === '' && $wire.form.tags.length) $wire.removeTag($wire.form.tags.at(-1))"
                            class="min-w-[140px] flex-1 border-0 px-1 py-0.5 text-sm outline-none">
                    </div>
                    <p class="mt-1.5 text-xs text-muted">Press Enter or comma to add. Up to {{ \App\Livewire\Forms\TemplateForm::MAX_TAGS }} tags.</p>
                    @error('form.tags') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                </div>
            </form>

            {{-- Live preview --}}
            <div class="lg:sticky lg:top-0 lg:self-start">
                <p class="mb-1.5 text-[13px] font-medium">Preview</p>
                <x-ui.whatsapp-preview live="preview" :attachments="$this->attachments" class="min-h-[240px]" />
                <p class="mt-2 text-xs text-muted">This is how the message looks in WhatsApp. Formatting: *bold*, _italic_, ~strike~, ```monospace```.</p>
            </div>
        </div>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button type="submit" form="template-form" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">{{ $form->template ? 'Save changes' : 'Create template' }}</span>
                <span wire:loading wire:target="save">Saving...</span>
            </x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <livewire:media.picker />
</div>

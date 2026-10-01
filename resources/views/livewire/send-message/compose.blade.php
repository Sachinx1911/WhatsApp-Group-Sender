<div x-data="{
        leaving: false,
        get dirty() { return ! this.leaving && !! ($wire.form.message || $wire.form.attachment_id || $wire.form.groups.length) },
    }"
    x-on:campaign-started.window="leaving = true"
    x-on:beforeunload.window="if (dirty) { $event.preventDefault(); $event.returnValue = '' }"
    class="pb-32 sm:pb-24">
    <x-ui.page-header title="Send Message" subtitle="Create and distribute educational content to selected WhatsApp groups" />

    @php
        $attachment = $this->attachment;
        $selection = $this->selection;
        $threshold = (int) config('educationhub.sending.large_selection_threshold');
        $footer = config('educationhub.message.auto_add_footer') ? config('educationhub.message.footer') : null;
    @endphp

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_440px]">
        {{-- ============ LEFT: composer ============ --}}
        <div class="min-w-0 space-y-6" x-data="messageEditor($wire.entangle('form.message'))">
            {{-- Message --}}
            <x-ui.card title="Message">
                <x-slot:actions>
                    <x-ui.button variant="secondary" size="sm" icon="file-text" x-on:click="$dispatch('pick-template')">Insert Template</x-ui.button>
                    <x-ui.button variant="ghost" size="sm" icon="eraser" x-show="text" x-on:click="if (confirm('Clear the message?')) $wire.clearMessage()">Clear</x-ui.button>
                </x-slot:actions>

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
                                    <button type="button" x-on:click="insert(@js($emoji)); emoji = false" class="grid size-7 place-items-center rounded-md text-lg hover:bg-canvas">{{ $emoji }}</button>
                                @endforeach
                            </div>
                        </div>
                        @if (config('educationhub.message.show_counter'))
                        <span class="ml-auto pr-1 text-xs text-muted" :class="length > {{ \App\Support\WhatsAppFormatter::MAX_LENGTH }} && 'font-medium text-danger'">
                            <span x-text="length.toLocaleString()"></span> / {{ number_format(\App\Support\WhatsAppFormatter::MAX_LENGTH) }}
                        </span>
                        @endif
                    </div>
                    <textarea wire:ignore x-ref="textarea" x-model="text" rows="11" aria-label="Message" placeholder="Write your message here..."
                        class="block w-full resize-y border-0 px-4 py-3 text-[15px] leading-relaxed outline-none"></textarea>
                </div>
                @error('form.message') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                <p class="mt-2 text-xs text-muted">Marathi, English and emoji are supported. Formatting: *bold*, _italic_, ~strike~, ```monospace```.</p>
            </x-ui.card>

            {{-- Attachment --}}
            <x-ui.card title="Attachments" subtitle="Images and PDFs · up to {{ \App\Livewire\Forms\SendForm::MAX_ATTACHMENTS }} · optional">
                @if ($this->attachments->isNotEmpty())
                    <ul class="space-y-2">
                        @foreach ($this->attachments as $file)
                            <li class="flex items-center gap-3 rounded-xl border border-line p-3">
                                @if ($file->isImage())
                                    <img src="{{ route('media.thumbnail', $file) }}" alt="" class="size-14 rounded-lg object-cover">
                                @else
                                    <span class="grid h-14 w-12 place-items-center rounded-lg bg-danger-soft text-xs font-bold text-danger">PDF</span>
                                @endif
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium">{{ $file->original_name }}</span>
                                    <span class="block text-xs text-muted">{{ $file->type->label() }} · {{ $file->humanSize() }}</span>
                                </span>
                                <button type="button" wire:click="removeAttachment({{ $file->id }})" class="rounded-lg p-2 text-muted hover:bg-danger-soft hover:text-danger" aria-label="Remove {{ $file->original_name }}">
                                    <x-lucide-x class="size-4" />
                                </button>
                            </li>
                        @endforeach
                    </ul>

                    @if ($this->attachments->count() < \App\Livewire\Forms\SendForm::MAX_ATTACHMENTS)
                        <div x-data="{ uploading: false, progress: 0 }"
                            x-on:livewire-upload-start="uploading = true; progress = 0"
                            x-on:livewire-upload-progress="progress = $event.detail.progress"
                            x-on:livewire-upload-finish="uploading = false"
                            x-on:livewire-upload-error="uploading = false"
                            class="mt-3 flex flex-wrap items-center gap-2">
                            <input x-ref="more" wire:model="upload" type="file" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" class="sr-only">
                            <x-ui.button variant="secondary" size="sm" icon="plus" x-on:click="$refs.more.click()">Add file</x-ui.button>
                            <x-ui.button variant="secondary" size="sm" icon="image" x-on:click="$dispatch('pick-media', { context: '{{ \App\Livewire\SendMessage\Compose::PICKER_CONTEXT }}', type: '{{ config('educationhub.message.default_type') === 'text' ? '' : config('educationhub.message.default_type') }}' })">Add from Media Library</x-ui.button>
                            <span x-show="uploading" x-cloak class="text-xs text-muted" x-text="`Uploading ${progress}%`"></span>
                        </div>
                    @endif

                    @if ($this->attachments->count() > 1 && $this->mixedAttachmentKinds)
                        {{-- WhatsApp cannot put photos and documents in one message. --}}
                        <p class="mt-3 flex items-start gap-2 rounded-xl bg-canvas px-3.5 py-2.5 text-xs text-muted">
                            <x-lucide-info class="mt-px size-3.5 shrink-0" />
                            Images and PDFs cannot share one WhatsApp message, so each group gets two: the images with your text, then the PDFs.
                        </p>
                    @endif
                @else
                    <div x-data="{ dragging: false, uploading: false, progress: 0 }"
                        x-on:livewire-upload-start="uploading = true; progress = 0"
                        x-on:livewire-upload-progress="progress = $event.detail.progress"
                        x-on:livewire-upload-finish="uploading = false"
                        x-on:livewire-upload-error="uploading = false">
                        <label x-on:dragover.prevent="dragging = true" x-on:dragleave.prevent="dragging = false"
                            x-on:drop.prevent="dragging = false; $refs.file.files = $event.dataTransfer.files; $refs.file.dispatchEvent(new Event('change'))"
                            :class="dragging ? 'border-primary bg-primary-soft' : 'border-line bg-canvas'"
                            class="flex cursor-pointer flex-col items-center rounded-2xl border-2 border-dashed px-6 py-7 text-center transition hover:border-primary/50">
                            <x-lucide-upload x-show="!uploading" class="size-6 text-primary" />
                            <x-lucide-loader-circle x-show="uploading" x-cloak class="size-6 animate-spin text-primary" />
                            <span class="mt-2 text-sm font-medium">Drag & drop images or PDFs here</span>
                            <span class="mt-1 text-xs text-muted">JPG, JPEG, PNG or PDF · add them one at a time</span>
                            <span x-show="uploading" x-cloak class="mt-2 text-xs text-muted" x-text="`Uploading ${progress}%`"></span>
                            <input x-ref="file" wire:model="upload" type="file" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" class="sr-only">
                        </label>
                        <div class="mt-3 flex flex-wrap justify-center gap-2">
                            <x-ui.button variant="secondary" size="sm" icon="folder-open" x-on:click="$refs.file.click()">Browse Files</x-ui.button>
                            <x-ui.button variant="secondary" size="sm" icon="image" x-on:click="$dispatch('pick-media', { context: '{{ \App\Livewire\SendMessage\Compose::PICKER_CONTEXT }}', type: '{{ config('educationhub.message.default_type') === 'text' ? '' : config('educationhub.message.default_type') }}' })">Choose from Media Library</x-ui.button>
                        </div>
                    </div>
                @endif
                @error('upload') <p class="mt-2 text-xs text-danger">{{ $message }}</p> @enderror
                @error('form.attachment_ids') <p class="mt-2 text-xs text-danger">{{ $message }}</p> @enderror
            </x-ui.card>

            {{-- Preview --}}
            <x-ui.card title="Message Preview" subtitle="How the message will look in WhatsApp">
                <x-ui.whatsapp-preview live="preview" :attachments="$this->attachments" :footer="$footer" class="min-h-[160px]" />
            </x-ui.card>
        </div>

        {{-- ============ RIGHT: groups ============ --}}
        <div class="min-w-0">
            <x-ui.card title="Select Groups" :padding="false" class="xl:sticky xl:top-[92px]">
                <x-slot:actions>
                    <span @class(['rounded-full px-3 py-1 text-[13px] font-semibold', 'bg-primary text-white' => $selection['count'], 'bg-canvas text-muted' => ! $selection['count']])>
                        {{ $selection['count'] }} {{ Str::plural('Group', $selection['count']) }} Selected
                    </span>
                </x-slot:actions>

                <div class="space-y-2.5 border-b border-line p-4">
                    <div class="relative">
                        <x-lucide-search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
                        <input wire:model.live.debounce.300ms="groupSearch" type="search" placeholder="Search groups or categories" aria-label="Search groups"
                            class="w-full rounded-xl border border-line py-2 pl-9 pr-3 text-sm outline-none focus:border-primary focus:ring-4 focus:ring-primary/10">
                    </div>
                    <div class="flex gap-2">
                        <select wire:model.live="groupCategory" aria-label="Filter by category" class="min-w-0 flex-1 rounded-xl border border-line bg-white py-2 pl-3 pr-8 text-sm outline-none focus:border-primary">
                            <option value="">All categories</option>
                            @foreach ($this->categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                        <div class="flex shrink-0 rounded-xl border border-line p-0.5 text-[13px]" role="group" aria-label="Show">
                            @foreach (array_filter(['active' => 'Active', 'inactive' => config('educationhub.groups.show_inactive_in_selector') ? 'Inactive' : null, 'selected' => 'Selected']) as $value => $label)
                                <button type="button" wire:click="$set('groupView', '{{ $value }}')" aria-pressed="{{ $groupView === $value ? 'true' : 'false' }}"
                                    @class(['rounded-lg px-2.5 py-1.5 transition', 'bg-primary text-white' => $groupView === $value, 'text-muted hover:text-ink' => $groupView !== $value])>{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <x-ui.button variant="secondary" size="sm" icon="list-checks" wire:click="selectAllMatching">
                            Select All{{ $groupSearch !== '' || $groupCategory !== '' ? ' matching' : '' }}
                        </x-ui.button>
                        <x-ui.button variant="ghost" size="sm" icon="x" wire:click="clearSelection" :disabled="! $selection['count']">Clear</x-ui.button>
                    </div>
                </div>

                @error('form.groups') <p class="border-b border-line bg-danger-soft px-4 py-2 text-xs text-danger">{{ $message }}</p> @enderror
                @error('form.groups.*') <p class="border-b border-line bg-danger-soft px-4 py-2 text-xs text-danger">{{ $message }}</p> @enderror

                @if ($groups->isEmpty())
                    <x-ui.empty-state icon="users" :title="$groupView === 'selected' ? 'No groups selected yet' : 'No groups match'" class="py-10">
                        {{ $groupView === 'selected' ? 'Tick groups in the Active list.' : 'Try a different search or category.' }}
                    </x-ui.empty-state>
                @else
                    <ul class="max-h-[520px] divide-y divide-line overflow-y-auto" wire:loading.class="opacity-60" wire:target="groupSearch,groupCategory,groupView,gotoPage,nextPage,previousPage">
                        @foreach ($groups as $group)
                            <li wire:key="send-group-{{ $group->id }}">
                                <label @class(['flex items-center gap-3 px-4 py-2.5 transition',
                                    'cursor-pointer hover:bg-canvas has-checked:bg-primary-soft/50' => $group->isActive(),
                                    'cursor-not-allowed opacity-60' => ! $group->isActive()])>
                                    <input type="checkbox" wire:model.live="form.groups" value="{{ $group->id }}" @disabled(! $group->isActive())
                                        class="size-4 accent-primary" aria-label="Select {{ $group->name }}">
                                    <span class="grid size-9 shrink-0 place-items-center rounded-lg text-white" style="background-color: {{ $group->category->color ?? '#64748B' }}">
                                        <x-lucide-users class="size-4" />
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-[13px] font-medium">{{ $group->name }}</span>
                                        <span class="block truncate text-xs text-muted">
                                            {{ $group->category->name }}{{ $group->member_count !== null ? ' · '.number_format($group->member_count).' members' : '' }}
                                        </span>
                                    </span>
                                    @unless ($group->isActive())
                                        <x-ui.badge>Inactive</x-ui.badge>
                                    @endunless
                                </label>
                            </li>
                        @endforeach
                    </ul>

                    @if ($groups->hasPages())
                        <div class="flex items-center justify-between border-t border-line px-4 py-2.5 text-xs text-muted">
                            <span>{{ $groups->firstItem() }}–{{ $groups->lastItem() }} of {{ $groups->total() }}</span>
                            <div class="flex gap-1">
                                <x-ui.button variant="secondary" size="sm" wire:click="previousPage('groupsPage')" :disabled="$groups->onFirstPage()" aria-label="Previous page">
                                    <x-lucide-chevron-left class="size-4" />
                                </x-ui.button>
                                <x-ui.button variant="secondary" size="sm" wire:click="nextPage('groupsPage')" :disabled="! $groups->hasMorePages()" aria-label="Next page">
                                    <x-lucide-chevron-right class="size-4" />
                                </x-ui.button>
                            </div>
                        </div>
                    @endif
                @endif

                @if ($selection['count'])
                    <div class="border-t border-line bg-canvas px-4 py-3 text-xs text-muted">
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($selection['categories'] as $name => $count)
                                <span class="rounded-full bg-white px-2 py-0.5 ring-1 ring-line">{{ $name }} · <b class="text-ink">{{ $count }}</b></span>
                            @endforeach
                        </div>
                        @if ($selection['members'])
                            <p class="mt-2">About {{ number_format($selection['members']) }} students will receive this message.</p>
                        @endif
                    </div>
                @endif
            </x-ui.card>
        </div>
    </div>

    {{-- ============ Sticky action bar ============ --}}
    <div class="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-white/95 backdrop-blur lg:left-60 lg:collapsed:left-[76px]">
        <div class="mx-auto flex max-w-[1440px] flex-wrap items-center gap-3 px-4 py-3 sm:px-6 lg:px-8">
            <p class="text-[13px] text-muted">
                <b class="text-ink">{{ $selection['count'] }}</b> {{ Str::plural('group', $selection['count']) }} selected
                @if ($selection['count'])
                    · Estimated time <b class="text-ink">{{ $this->estimate() }}</b>
                @endif
            </p>
            <div class="flex w-full gap-2 sm:ml-auto sm:w-auto">
                <div class="hidden sm:block"><x-ui.button variant="ghost" x-on:click="if (! dirty || confirm('Discard this message?')) { leaving = true; window.location = '{{ route('dashboard') }}' }">Cancel</x-ui.button></div>
                <x-ui.button variant="secondary" icon="flask-conical" class="flex-1 sm:flex-none" wire:click="sendTest" wire:loading.attr="disabled" wire:target="sendTest">Send Test</x-ui.button>
                <x-ui.button icon="send" class="flex-1 sm:flex-none" wire:click="review" wire:loading.attr="disabled" wire:target="review">Review & Send</x-ui.button>
            </div>
        </div>
    </div>

    {{-- ============ Review & Send ============ --}}
    <x-ui.modal :name="\App\Livewire\SendMessage\Compose::REVIEW_MODAL" title="Review & Send" max-width="max-w-3xl">
        <div class="grid gap-5 md:grid-cols-[minmax(0,1fr)_280px]">
            <div>
                <p class="mb-1.5 text-[13px] font-medium">Message</p>
                <x-ui.whatsapp-preview :text="$form->message" :attachments="$this->attachments" :footer="$footer" class="max-h-[420px] overflow-y-auto" />
            </div>
            <div class="space-y-4">
                <dl class="divide-y divide-line rounded-xl border border-line text-[13px]">
                    <div class="flex justify-between gap-3 px-3.5 py-2.5"><dt class="text-muted">Groups</dt><dd class="text-base font-semibold">{{ $selection['count'] }}</dd></div>
                    @if ($selection['members'])
                        <div class="flex justify-between gap-3 px-3.5 py-2.5"><dt class="text-muted">Students (approx.)</dt><dd class="font-medium">{{ number_format($selection['members']) }}</dd></div>
                    @endif
                    <div class="flex justify-between gap-3 px-3.5 py-2.5">
                        <dt class="text-muted">{{ $this->attachments->count() > 1 ? 'Attachments' : 'Attachment' }}</dt>
                        <dd class="min-w-0 text-right font-medium">
                            @forelse ($this->attachments as $file)
                                <span class="block truncate">{{ $file->original_name }} ({{ $file->humanSize() }})</span>
                            @empty
                                None
                            @endforelse
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3 px-3.5 py-2.5"><dt class="text-muted">Estimated time</dt><dd class="font-medium">{{ $this->estimate() }}</dd></div>
                </dl>
                <div>
                    <p class="mb-1.5 text-[13px] font-medium">Categories included</p>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($selection['categories'] as $name => $count)
                            <x-ui.badge color="primary">{{ $name }} · {{ $count }}</x-ui.badge>
                        @endforeach
                    </div>
                </div>
                <div class="rounded-xl border border-line p-3">
                    <p class="mb-2 text-[13px] font-medium">When to send</p>
                    <div class="grid grid-cols-2 gap-1.5 rounded-lg bg-canvas p-1 text-[13px]">
                        <label class="cursor-pointer rounded-md px-3 py-1.5 text-center font-medium transition {{ $form->when === 'now' ? 'bg-white text-ink shadow-sm' : 'text-muted hover:text-ink' }}">
                            <input type="radio" wire:model.live="form.when" value="now" class="sr-only"> Send now
                        </label>
                        <label class="cursor-pointer rounded-md px-3 py-1.5 text-center font-medium transition {{ $form->when === 'later' ? 'bg-white text-ink shadow-sm' : 'text-muted hover:text-ink' }}">
                            <input type="radio" wire:model.live="form.when" value="later" class="sr-only"> Schedule
                        </label>
                    </div>
                    @if ($form->when === 'later')
                        <div class="mt-2.5">
                            <p class="mb-1.5 text-xs text-muted">Choose a day and time in the future you want the message to go out.</p>
                            <x-ui.datetime-picker model="form.scheduledFor" />
                            @error('form.scheduledFor') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                            <p class="mt-1.5 text-xs text-muted">The app (start.bat) and WhatsApp must be running at that time. If the computer is off, it is sent as soon as the app starts again.</p>
                        </div>
                    @endif
                </div>
                <p class="flex gap-2 rounded-xl bg-canvas px-3 py-2.5 text-xs text-muted">
                    <x-lucide-info class="mt-px size-3.5 shrink-0" />
                    Groups are sent one at a time with a {{ config('educationhub.sending.delay_seconds') }}-second pause. Keep this computer on and WhatsApp connected until it finishes.
                </p>
                @if ($selection['count'] > $threshold)
                    <div>
                        <label for="confirm-count" class="mb-1.5 block text-[13px] font-medium text-amber-700">
                            This is a large send. Type <b>{{ $selection['count'] }}</b> to confirm.
                        </label>
                        <input wire:model="form.confirmCount" id="confirm-count" type="text" inputmode="numeric" autocomplete="off"
                            class="w-full rounded-xl border border-amber-300 px-3.5 py-2 text-sm outline-none focus:border-warning focus:ring-4 focus:ring-warning/20">
                        @error('form.confirmCount') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                @endif
            </div>
        </div>
        <x-slot:footer>
            <x-ui.button variant="secondary" icon="arrow-left" x-on:click="open = false">Back</x-ui.button>
            <x-ui.button :icon="$form->when === 'later' ? 'calendar-clock' : 'send'" wire:click="startSending" wire:loading.attr="disabled" wire:target="startSending">
                <span wire:loading.remove wire:target="startSending">{{ $form->when === 'later' ? 'Schedule' : 'Start Sending' }}</span>
                <span wire:loading wire:target="startSending">{{ $form->when === 'later' ? 'Scheduling...' : 'Starting...' }}</span>
            </x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- ============ Send Test ============ --}}
    <x-ui.modal :name="\App\Livewire\SendMessage\Compose::TEST_MODAL" title="Send a test first?">
        <div class="flex gap-4">
            <span class="grid size-10 shrink-0 place-items-center rounded-full bg-accent-soft text-accent"><x-lucide-flask-conical class="size-5" /></span>
            <div class="text-sm">
                <p>The message will be sent only to <b>{{ $this->testGroup?->name }}</b>{{ $this->testGroup?->member_count ? ' ('.$this->testGroup->member_count.' members)' : '' }}.</p>
                <p class="mt-2 text-muted">Check how it looks on your phone before sending to all groups. The test appears in Send History with a “Test” badge.</p>
            </div>
        </div>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button icon="flask-conical" wire:click="startTest" wire:loading.attr="disabled" wire:target="startTest">Send Test</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <livewire:templates.picker />
    <livewire:media.picker />
</div>

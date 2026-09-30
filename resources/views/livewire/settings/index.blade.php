@php
    $sections = \App\Livewire\Settings\Index::SECTIONS;
    [$sectionLabel, $sectionIcon, $sectionDescription] = $sections[$section];
    $input = 'w-full rounded-xl border border-line bg-white px-3.5 py-2.5 text-sm outline-none transition focus:border-primary focus:ring-4 focus:ring-primary/10';
    $err = fn ($field) => $errors->first("s.{$field}");
@endphp

<div>
    <x-ui.page-header title="Settings" subtitle="Configure your WhatsApp sender, message settings and application preferences" />

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[240px_minmax(0,1fr)]">
        {{-- Section navigation --}}
        <nav class="lg:sticky lg:top-[92px] lg:self-start" aria-label="Settings sections">
            <ul class="flex gap-1 overflow-x-auto rounded-card border border-line bg-white p-2 shadow-card lg:flex-col lg:overflow-visible">
                @foreach ($sections as $key => [$label, $icon])
                    <li class="shrink-0">
                        <button type="button" wire:click="$set('section', '{{ $key }}')" @if ($section === $key) aria-current="page" @endif
                            @class(['flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm transition',
                                'bg-primary-soft font-medium text-primary' => $section === $key,
                                'text-muted hover:bg-canvas hover:text-ink' => $section !== $key])>
                            <x-dynamic-component :component="'lucide-'.$icon" class="size-4 shrink-0" />
                            <span class="whitespace-nowrap">{{ $label }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        </nav>

        <x-ui.card :title="$sectionLabel" :subtitle="$sectionDescription" class="min-w-0" wire:key="section-{{ $section }}">
            @switch($section)
                {{-- ================= WhatsApp ================= --}}
                @case('whatsapp')
                    @php
                        $session = $this->session;
                        $health = $this->health;
                        $practice = config('educationhub.whatsapp.driver') === 'fake';
                        $waiting = in_array($session->status, [\App\Enums\WhatsAppConnectionStatus::Starting, \App\Enums\WhatsAppConnectionStatus::WaitingForQr], true);
                    @endphp
                    <div @if ($waiting) wire:poll.3s @else wire:poll.30s.visible @endif>
                        <div class="flex flex-wrap items-center gap-4 rounded-xl border border-line p-4">
                            <span @class(['grid size-12 place-items-center rounded-xl',
                                'bg-success-soft text-success' => $session->isConnected(),
                                'bg-warning-soft text-warning' => $waiting,
                                'bg-danger-soft text-danger' => ! $session->isConnected() && ! $waiting])>
                                <x-lucide-message-circle class="size-6" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm text-muted">Status</p>
                                <p class="text-lg font-semibold">{{ $session->isConnected() ? '🟢' : ($waiting ? '🟡' : '🔴') }} {{ $session->status->label() }}</p>
                                @if ($session->last_connected_at)
                                    <p class="text-xs text-muted">Last connected {{ $session->last_connected_at->format('d M Y, g:i A') }}</p>
                                @endif
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @if ($session->isConnected())
                                    <x-ui.button variant="danger" size="sm" icon="log-out" x-on:click="$dispatch('open-modal', '{{ \App\Livewire\Settings\Index::DISCONNECT_MODAL }}')">Disconnect</x-ui.button>
                                @else
                                    <x-ui.button size="sm" icon="qr-code" wire:click="connectWhatsApp" wire:loading.attr="disabled" wire:target="connectWhatsApp">{{ $waiting ? 'Open WhatsApp Web again' : 'Connect WhatsApp' }}</x-ui.button>
                                @endif
                                <x-ui.button variant="secondary" size="sm" icon="refresh-cw" wire:click="refreshSession" wire:loading.attr="disabled" wire:target="refreshSession">Refresh Session</x-ui.button>
                            </div>
                        </div>

                        @if ($waiting)
                            <div class="mt-4 rounded-xl border border-amber-200 bg-warning-soft px-4 py-3 text-[13px] text-amber-900">
                                <p class="mb-1.5 flex items-center gap-2 font-semibold"><x-lucide-smartphone class="size-4" /> Scan the QR code to link this computer</p>
                                <ol class="list-decimal space-y-0.5 pl-5">
                                    <li>A Chromium window with WhatsApp Web has opened on this computer.</li>
                                    <li>On your phone open WhatsApp → <b>Settings</b> (or ⋮) → <b>Linked devices</b> → <b>Link a device</b>.</li>
                                    <li>Point the phone at the QR code. This page updates by itself when you are connected.</li>
                                </ol>
                            </div>
                        @endif

                        <dl class="mt-4 divide-y divide-line text-[13px]">
                            <div class="flex justify-between gap-4 py-2.5"><dt class="text-muted">Sending engine</dt>
                                <dd class="text-right font-medium">{{ $practice ? 'Practice mode (nothing is really sent)' : 'WhatsApp Web (Playwright)' }}</dd></div>
                            <div class="flex justify-between gap-4 py-2.5"><dt class="text-muted">WhatsApp worker</dt>
                                <dd class="text-right">
                                    @if ($practice)
                                        <span class="font-medium text-success">Built in</span>
                                    @elseif ($health['worker'])
                                        <span class="font-medium text-success">Running</span>
                                        <span class="block text-xs text-muted">Last report {{ $session->last_seen_at->diffForHumans() }}</span>
                                    @else
                                        <span class="font-medium text-danger">Not running</span>
                                        <span class="block text-xs text-muted">Start the app with <b>start.bat</b></span>
                                    @endif
                                </dd></div>
                            <div class="flex justify-between gap-4 py-2.5"><dt class="text-muted">Sending queue</dt>
                                <dd class="text-right">
                                    @if ($health['queueStalled'])
                                        <span class="font-medium text-danger">Not picking up messages</span>
                                        <span class="block text-xs text-muted">Close the app and start it again with <b>start.bat</b></span>
                                    @elseif ($health['sending'])
                                        <span class="font-medium text-success">Sending</span>
                                        <a href="{{ route('campaigns.show', $health['sending']) }}" class="block text-xs text-primary hover:underline">{{ $health['sending']->title }}</a>
                                    @else
                                        <span class="font-medium">Idle</span>
                                    @endif
                                </dd></div>
                            <div class="flex justify-between gap-4 py-2.5"><dt class="text-muted">Browser profile</dt><dd class="font-medium">{{ $session->profile_name }}</dd></div>
                        </dl>

                        <p class="mt-4 flex gap-2 rounded-xl bg-primary-soft px-3.5 py-3 text-[13px] text-blue-800">
                            <x-lucide-info class="mt-0.5 size-4 shrink-0" />
                            @if ($practice)
                                Practice mode: Connect and Disconnect work instantly without a QR code, so you can try the app safely. The real WhatsApp Web connection is added in Phase 14.
                            @else
                                The login stays on this computer only (a private browser profile). If WhatsApp disconnects while sending, the campaign pauses and you can resume it after connecting again.
                            @endif
                        </p>
                    </div>
                    @break

                {{-- ================= Sending ================= --}}
                @case('sending')
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="s-delay" class="mb-1.5 block text-[13px] font-medium">Delay between groups (seconds)</label>
                            <input wire:model="s.delay_seconds" id="s-delay" type="number" min="5" max="300" class="{{ $input }}">
                            <p class="mt-1.5 text-xs {{ $err('delay_seconds') ? 'text-danger' : 'text-muted' }}">{{ $err('delay_seconds') ?: 'A fixed pause after each group. Minimum 5 seconds; 15 is recommended.' }}</p>
                        </div>
                        <div>
                            <label for="s-daily" class="mb-1.5 block text-[13px] font-medium">Daily sending limit</label>
                            <input wire:model="s.daily_limit" id="s-daily" type="number" min="0" class="{{ $input }}">
                            <p class="mt-1.5 text-xs {{ $err('daily_limit') ? 'text-danger' : 'text-muted' }}">{{ $err('daily_limit') ?: 'Groups per day. Sending pauses when it is reached. 0 = no limit.' }}</p>
                        </div>
                        <div>
                            <label for="s-max" class="mb-1.5 block text-[13px] font-medium">Maximum groups per campaign</label>
                            <input wire:model="s.max_groups_per_campaign" id="s-max" type="number" min="1" class="{{ $input }}">
                            @if ($err('max_groups_per_campaign')) <p class="mt-1.5 text-xs text-danger">{{ $err('max_groups_per_campaign') }}</p> @endif
                        </div>
                        <div>
                            <label for="s-test" class="mb-1.5 block text-[13px] font-medium">Test group</label>
                            <select wire:model="s.test_group_id" id="s-test" class="{{ $input }}">
                                <option value="">“Education Hub Test Group” (by name)</option>
                                @foreach ($this->groups as $group)
                                    <option value="{{ $group->id }}">{{ $group->name }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1.5 text-xs {{ $err('test_group_id') ? 'text-danger' : 'text-muted' }}">{{ $err('test_group_id') ?: 'Used by the Send Test button. Use a group with only you and a colleague.' }}</p>
                        </div>
                    </div>
                    <div class="mt-4 divide-y divide-line border-t border-line">
                        <x-ui.toggle wire:model="s.show_progress" label="Show progress during sending"
                            description="Open the live progress screen after Start Sending (otherwise go to Send History)." />
                        <div class="flex items-start justify-between gap-6 py-3.5">
                            <span>
                                <span class="block text-sm font-medium">Skip groups already sent in this campaign</span>
                                <span class="mt-0.5 block text-xs text-muted">Always on. After a pause, restart or retry, a group never receives the same campaign twice.</span>
                            </span>
                            <x-ui.badge color="success">Always on</x-ui.badge>
                        </div>
                    </div>
                    @break

                {{-- ================= Message ================= --}}
                @case('message')
                    <div class="grid gap-5">
                        <div>
                            <p class="mb-1.5 text-[13px] font-medium">Default message type</p>
                            <div class="grid gap-2 sm:grid-cols-3">
                                @foreach (['text' => 'Text only', 'image' => 'Text + Image', 'pdf' => 'Text + PDF'] as $value => $label)
                                    <label class="flex cursor-pointer items-center gap-2.5 rounded-xl border border-line px-3.5 py-2.5 text-sm has-checked:border-primary has-checked:bg-primary-soft">
                                        <input wire:model="s.default_type" type="radio" value="{{ $value }}" class="accent-primary"> {{ $label }}
                                    </label>
                                @endforeach
                            </div>
                            <p class="mt-1.5 text-xs text-muted">“Choose from Media Library” on Send Message opens with this type selected.</p>
                        </div>
                        <div>
                            <label for="s-footer" class="mb-1.5 block text-[13px] font-medium">Default footer / signature</label>
                            <textarea wire:model="s.footer" id="s-footer" rows="3" placeholder="_— Education Hub_" class="{{ $input }}"></textarea>
                            <p class="mt-1.5 text-xs {{ $err('footer') ? 'text-danger' : 'text-muted' }}">{{ $err('footer') ?: 'Marathi and WhatsApp formatting are supported.' }}</p>
                        </div>
                    </div>
                    <div class="mt-4 divide-y divide-line border-t border-line">
                        <x-ui.toggle wire:model="s.auto_add_footer" label="Auto-add footer" description="Adds the footer below every message you send. It is shown in the preview." />
                        <x-ui.toggle wire:model="s.link_preview" label="Enable link preview" description="Let WhatsApp show a preview card for links in the message." />
                        <x-ui.toggle wire:model="s.show_counter" label="Show character counter" description="Shown in the message editor and on Send Message." />
                    </div>
                    @break

                {{-- ================= Media ================= --}}
                @case('media')
                    <div class="grid gap-5 sm:grid-cols-3">
                        @foreach ([['max_image_mb', 'Maximum image size (MB)', 'Up to 16 MB.'], ['max_pdf_mb', 'Maximum PDF size (MB)', 'Up to 100 MB.'], ['storage_quota_gb', 'Storage quota (GB)', 'Shown in the sidebar.']] as [$field, $label, $hint])
                            <div>
                                <label for="s-{{ $field }}" class="mb-1.5 block text-[13px] font-medium">{{ $label }}</label>
                                <input wire:model="s.{{ $field }}" id="s-{{ $field }}" type="number" min="1" class="{{ $input }}">
                                <p class="mt-1.5 text-xs {{ $err($field) ? 'text-danger' : 'text-muted' }}">{{ $err($field) ?: $hint }}</p>
                            </div>
                        @endforeach
                    </div>
                    <p class="mt-4 text-[13px]"><span class="text-muted">Allowed file types:</span> <b>JPG, JPEG, PNG, PDF</b> <span class="text-muted">(fixed)</span></p>
                    <div class="mt-3 divide-y divide-line border-t border-line">
                        <x-ui.toggle wire:model="s.compress_images" label="Compress large images" description="Photos wider than 2560 px are resized when uploaded, to save space." />
                        <x-ui.toggle wire:model="s.generate_thumbnails" label="Generate thumbnails" description="Small previews for the Media Library. Turn off to save a little space." />
                        <div class="flex items-center justify-between gap-6 py-3.5">
                            <label for="s-temp" class="text-sm font-medium">Delete unfinished uploads after (days)</label>
                            <input wire:model="s.temp_retention_days" id="s-temp" type="number" min="1" max="30" class="w-24 rounded-xl border border-line px-3 py-2 text-sm outline-none focus:border-primary">
                        </div>
                        @if ($err('temp_retention_days')) <p class="pb-2 text-xs text-danger">{{ $err('temp_retention_days') }}</p> @endif
                    </div>
                    @break

                {{-- ================= Groups ================= --}}
                @case('groups')
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="s-cat" class="mb-1.5 block text-[13px] font-medium">Default category</label>
                            <select wire:model="s.default_category_id" id="s-cat" class="{{ $input }}">
                                <option value="">Automatic (“Other”, or the first category)</option>
                                @foreach ($this->categories as $category)
                                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1.5 text-xs text-muted">Used for new groups and CSV rows without a category.</p>
                        </div>
                        <div>
                            <label for="s-threshold" class="mb-1.5 block text-[13px] font-medium">Confirm by typing above (groups)</label>
                            <input wire:model="s.large_selection_threshold" id="s-threshold" type="number" min="1" class="{{ $input }}">
                            <p class="mt-1.5 text-xs {{ $err('large_selection_threshold') ? 'text-danger' : 'text-muted' }}">{{ $err('large_selection_threshold') ?: 'For bigger sends you must type the number of groups before sending.' }}</p>
                        </div>
                        <div>
                            <p class="mb-1.5 text-[13px] font-medium">Group search</p>
                            <div class="grid grid-cols-2 gap-2">
                                @foreach (['contains' => 'Name contains', 'starts_with' => 'Name starts with'] as $value => $label)
                                    <label class="flex cursor-pointer items-center gap-2.5 rounded-xl border border-line px-3.5 py-2.5 text-sm has-checked:border-primary has-checked:bg-primary-soft">
                                        <input wire:model="s.search_mode" type="radio" value="{{ $value }}" class="accent-primary"> {{ $label }}
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    <div class="mt-4 divide-y divide-line border-t border-line">
                        <x-ui.toggle wire:model="s.show_inactive_in_selector" label="Show inactive groups in the selector" description="Adds an “Inactive” tab on Send Message (inactive groups can never be selected)." />
                        <x-ui.toggle wire:model="s.remember_selection" label="Remember previous group selection" description="Send Message starts with the groups you sent to last time." />
                    </div>
                    <div class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-canvas px-4 py-3.5">
                        <div>
                            <p class="text-sm font-medium">Categories</p>
                            <p class="text-xs text-muted">{{ $this->categories->pluck('name')->join(', ') }}</p>
                        </div>
                        <x-ui.button variant="secondary" size="sm" icon="tags" x-on:click="$dispatch('open-modal', '{{ \App\Livewire\Categories\Manager::MODAL }}')">Manage Categories</x-ui.button>
                    </div>
                    @break

                {{-- ================= Notifications ================= --}}
                @case('notifications')
                    <div x-data="{ permission: window.Notification ? Notification.permission : 'unsupported' }"
                        class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-line px-4 py-3.5">
                        <div>
                            <p class="text-sm font-medium">Browser permission</p>
                            <p class="text-xs text-muted" x-text="{
                                granted: 'Allowed — this browser can show desktop notifications.',
                                denied: 'Blocked in this browser. Allow notifications for this site in the browser settings.',
                                default: 'Not asked yet.',
                                unsupported: 'This browser does not support desktop notifications.',
                            }[permission]"></p>
                        </div>
                        <x-ui.button variant="secondary" size="sm" icon="bell-ring" x-show="permission === 'default'"
                            x-on:click="Notification.requestPermission().then(p => permission = p)">Allow notifications</x-ui.button>
                    </div>
                    <div class="mt-2 divide-y divide-line">
                        <x-ui.toggle wire:model="s.desktop" label="Desktop notifications" description="Show a Windows notification while the app is open, even if you are in another window. The bell in the header always shows everything." />
                        <x-ui.toggle wire:model="s.campaign_completed" label="Campaign completed" />
                        <x-ui.toggle wire:model="s.campaign_failures" label="Failed messages in a campaign" />
                        <x-ui.toggle wire:model="s.whatsapp_disconnected" label="WhatsApp disconnected" />
                        <x-ui.toggle wire:model="s.queue_stopped" label="Sending stopped (daily limit or sender not running)" />
                    </div>
                    @break

                {{-- ================= Appearance ================= --}}
                @case('appearance')
                    <div class="divide-y divide-line">
                        <x-ui.toggle wire:model="s.sidebar_collapsed" label="Sidebar collapsed by default" description="Show only icons in the sidebar. You can still toggle it with the ☰ button." />
                        <x-ui.toggle wire:model="s.compact_tables" label="Compact tables" description="Smaller row height, so more groups and messages fit on screen." />
                    </div>
                    <p class="mt-4 text-xs text-muted">Dark mode is planned for a later version.</p>
                    @break

                {{-- ================= Data & Backup ================= --}}
                @case('data')
                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="rounded-xl border border-line p-4">
                            <p class="flex items-center gap-2 text-sm font-semibold"><x-lucide-download class="size-4 text-primary" /> Export All Data</p>
                            <p class="mt-1 text-xs text-muted">A ZIP with all groups, templates, campaigns, history and settings (as JSON) plus every media file. Passwords and the WhatsApp session are never included.</p>
                            <x-ui.button size="sm" icon="archive" class="mt-3" wire:click="exportNow" wire:loading.attr="disabled" wire:target="exportNow">
                                <span wire:loading.remove wire:target="exportNow">Create Backup</span><span wire:loading wire:target="exportNow">Creating...</span>
                            </x-ui.button>
                        </div>
                        <div class="rounded-xl border border-line p-4">
                            <p class="flex items-center gap-2 text-sm font-semibold"><x-lucide-eraser class="size-4 text-primary" /> Clear Temporary Files</p>
                            <p class="mt-1 text-xs text-muted">Removes unfinished uploads older than {{ config('educationhub.media.temp_retention_days') }} {{ Str::plural('day', config('educationhub.media.temp_retention_days')) }}. Your media and backups are not touched.</p>
                            <x-ui.button variant="secondary" size="sm" icon="trash" class="mt-3" wire:click="clearTemporaryFiles" wire:loading.attr="disabled" wire:target="clearTemporaryFiles">Clear Temporary Files</x-ui.button>
                        </div>
                    </div>

                    <div class="mt-5">
                        <p class="mb-2 text-[13px] font-semibold">Backups on this computer</p>
                        @if ($this->backups)
                            <ul class="divide-y divide-line rounded-xl border border-line">
                                @foreach ($this->backups as $backup)
                                    <li wire:key="backup-{{ $backup['name'] }}" class="flex flex-wrap items-center gap-3 px-4 py-2.5 text-[13px]">
                                        <x-lucide-file-archive class="size-4 text-muted" />
                                        <span class="min-w-0 flex-1 truncate font-medium">{{ $backup['name'] }}</span>
                                        <span class="text-xs text-muted">{{ Number::fileSize($backup['size'], 1) }} · {{ \Illuminate\Support\Carbon::createFromTimestamp($backup['created'])->timezone(config('app.timezone'))->format('d M Y, g:i A') }}</span>
                                        <a href="{{ route('backups.download', $backup['name']) }}" class="font-medium text-primary hover:underline">Download</a>
                                        <button type="button" wire:click="deleteBackup(@js($backup['name']))" wire:confirm="Delete this backup file?" class="text-muted hover:text-danger" aria-label="Delete {{ $backup['name'] }}">
                                            <x-lucide-trash-2 class="size-4" />
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="rounded-xl bg-canvas px-4 py-3 text-xs text-muted">No backups yet.</p>
                        @endif
                        <p class="mt-2 text-xs text-muted">Restoring a backup is planned for a later version. Keep a copy of important backups outside this computer.</p>
                    </div>

                    <div class="mt-6 rounded-xl border border-red-200 bg-danger-soft/50 p-4">
                        <p class="flex items-center gap-2 text-sm font-semibold text-red-800"><x-lucide-triangle-alert class="size-4" /> Reset Application</p>
                        <p class="mt-1 text-xs text-red-800/90">Deletes all groups, templates, media, campaigns, history and settings. Your login and WhatsApp connection are kept. A backup is created automatically first.</p>
                        <x-ui.button variant="danger" size="sm" icon="rotate-ccw" class="mt-3" x-on:click="$dispatch('open-modal', '{{ \App\Livewire\Settings\Index::RESET_MODAL }}')">Reset Application…</x-ui.button>
                    </div>
                    @break
            @endswitch

            @if (! in_array($section, ['whatsapp', 'data'], true))
                <x-slot:actions>
                    <x-ui.button variant="ghost" size="sm" wire:click="restoreDefaults('{{ $section }}')" wire:confirm="Restore the defaults for {{ $sectionLabel }}?">Restore defaults</x-ui.button>
                    <x-ui.button size="sm" icon="check" wire:click="save('{{ $section }}')" wire:loading.attr="disabled" wire:target="save">Save changes</x-ui.button>
                </x-slot:actions>
            @endif
        </x-ui.card>
    </div>

    {{-- Disconnect confirmation --}}
    <x-ui.modal :name="\App\Livewire\Settings\Index::DISCONNECT_MODAL" title="Disconnect WhatsApp?">
        <div class="space-y-3 text-sm">
            <p>This logs WhatsApp Web out on this computer. To send again you will need to scan the QR code with your phone.</p>
            @if ($section === 'whatsapp' && ($sending = $this->health['sending']))
                <p class="rounded-xl bg-warning-soft px-3.5 py-2.5 text-amber-900">“{{ $sending->title }}” is sending right now. It will be <b>paused</b>; you can resume it after connecting again.</p>
            @endif
        </div>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button variant="danger" icon="log-out" wire:click="disconnectWhatsApp" wire:loading.attr="disabled" wire:target="disconnectWhatsApp">Disconnect</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- Reset confirmation --}}
    <x-ui.modal :name="\App\Livewire\Settings\Index::RESET_MODAL" title="Reset the application?">
        <div class="space-y-3 text-sm">
            <p>This deletes <b>all groups, templates, media files, campaigns, send history and settings</b>. A backup ZIP is created first so nothing is lost for good.</p>
            <p class="text-muted">Your admin login and the WhatsApp connection are kept. Default categories are restored.</p>
            <div>
                <label for="reset-confirm" class="mb-1.5 block text-[13px] font-medium">Type <b>RESET</b> to confirm</label>
                <input wire:model="resetConfirmation" id="reset-confirm" type="text" autocomplete="off"
                    class="w-full rounded-xl border border-red-300 px-3.5 py-2 text-sm outline-none focus:border-danger focus:ring-4 focus:ring-danger/10">
                @error('resetConfirmation') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
            </div>
        </div>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            <x-ui.button variant="danger" icon="rotate-ccw" wire:click="resetApplication" wire:loading.attr="disabled" wire:target="resetApplication">Reset application</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <livewire:categories.manager />
</div>

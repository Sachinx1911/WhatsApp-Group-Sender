@php
    use App\Enums\SendStatus;

    $kpis = $this->kpis;
@endphp

<div>
    <x-ui.page-header title="Failed Messages" subtitle="View messages that failed to send, check error reasons and retry" />

    {{-- KPIs --}}
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-ui.stat-card label="Total Failed" :value="number_format($kpis['failed'])" icon="triangle-alert" tone="danger" hint="Waiting for your decision" />
        <x-ui.stat-card label="Pending Retry" :value="number_format($kpis['pending_retry'])" icon="refresh-cw" tone="primary" hint="Queued to send again" />
        <x-ui.stat-card label="Permanent Failures" :value="number_format($kpis['permanent'])" icon="circle-alert" tone="warning" hint="Need a fix before retrying" />
        <x-ui.stat-card label="Affected Groups" :value="number_format($kpis['groups'])" icon="users" tone="accent" />
    </div>

    <x-ui.card :padding="false" class="mt-6">
        {{-- View tabs + filters --}}
        <div class="flex flex-wrap items-center gap-2 border-b border-line p-4">
            <div class="flex rounded-xl border border-line p-0.5 text-[13px]" role="group" aria-label="Show">
                @foreach (['failed' => 'Failed', 'retrying' => 'Retrying', 'skipped' => 'Resolved'] as $value => $label)
                    <button type="button" wire:click="$set('view', '{{ $value }}')" aria-pressed="{{ $view === $value ? 'true' : 'false' }}"
                        @class(['rounded-lg px-3 py-1.5 transition', 'bg-primary text-white' => $view === $value, 'text-muted hover:text-ink' => $view !== $value])>{{ $label }}</button>
                @endforeach
            </div>
            <div class="relative min-w-[220px] flex-1">
                <x-lucide-search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
                <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search group, error or message" aria-label="Search failed messages"
                    class="w-full rounded-xl border border-line bg-white py-2 pl-9 pr-3 text-sm outline-none focus:border-primary focus:ring-4 focus:ring-primary/10">
            </div>
            <select wire:model.live="errorType" aria-label="Filter by error" class="rounded-xl border border-line bg-white py-2 pl-3 pr-8 text-sm outline-none focus:border-primary">
                <option value="">All errors</option>
                @foreach ($this->errorTypes as $type)
                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                @endforeach
            </select>
            @if ($search !== '' || $errorType !== '')
                <x-ui.button variant="ghost" size="sm" icon="x" wire:click="clearFilters">Clear filters</x-ui.button>
            @endif
        </div>

        {{-- Bulk bar --}}
        @if ($view === 'failed')
            <div x-data x-show="$wire.selectAll || $wire.selected.length" x-cloak
                class="flex flex-wrap items-center gap-2 border-b border-line bg-primary-soft/60 px-4 py-2.5 text-[13px]">
                <span class="font-medium text-blue-800"><span x-text="$wire.selectAll ? {{ $rows->total() }} : $wire.selected.length"></span> selected</span>
                @if (! $selectAll && $rows->total() > $rows->count())
                    <button type="button" wire:click="$set('selectAll', true)" x-show="$wire.selected.length >= {{ $rows->count() }}" class="text-primary hover:underline">
                        Select all {{ $rows->total() }} failed messages
                    </button>
                @endif
                <div class="ml-auto flex items-center gap-2">
                    <x-ui.button size="sm" icon="refresh-cw" wire:click="retrySelected" wire:loading.attr="disabled" wire:target="retrySelected">Retry Selected</x-ui.button>
                    <x-ui.button variant="secondary" size="sm" icon="check" wire:click="skipSelected" wire:confirm="Mark the selected messages as resolved? They will not be sent.">Skip Selected</x-ui.button>
                    <button type="button" wire:click="$set('selected', []); $set('selectAll', false)" class="px-1 text-muted hover:text-ink">Clear</button>
                </div>
            </div>
        @endif

        {{-- Table --}}
        @if ($rows->isEmpty())
            @if ($view === 'failed' && $search === '' && $errorType === '')
                <div class="flex flex-col items-center px-6 py-14 text-center">
                    <span class="text-4xl" aria-hidden="true">🎉</span>
                    <h3 class="mt-3 text-[15px] font-semibold">No failed messages</h3>
                    <p class="mt-1 text-[13px] text-muted">All recent messages were sent successfully.</p>
                </div>
            @else
                <x-ui.empty-state icon="search-x" :title="$view === 'retrying' ? 'Nothing is waiting to be retried' : ($view === 'skipped' ? 'No resolved messages' : 'No failed messages match')" />
            @endif
        @else
            <div class="overflow-x-auto" wire:loading.class="opacity-60" wire:target="view,search,errorType,sortBy,gotoPage,nextPage,previousPage,perPage">
                <table class="w-full min-w-[980px] text-left text-[13px]">
                    <thead class="bg-canvas text-xs text-muted">
                        <tr>
                            <th class="w-10 py-2.5 pl-5 pr-2">
                                @if ($view === 'failed')
                                    <input type="checkbox" aria-label="Select all on this page" class="size-4 accent-primary"
                                        x-data="{ ids: @js($rows->pluck('id')->map(fn ($id) => (string) $id)->all()) }"
                                        x-bind:checked="$wire.selectAll || ids.every(id => $wire.selected.includes(id))"
                                        x-on:change="$wire.selectAll = false; $wire.selected = $event.target.checked ? [...new Set([...$wire.selected, ...ids])] : $wire.selected.filter(id => ! ids.includes(id))">
                                @endif
                            </th>
                            <x-ui.sort-header column="updated_at" :sort-by="$sortBy" :sort-dir="$sortDir">Date & Time</x-ui.sort-header>
                            <th class="px-3 py-2.5 font-medium">Message</th>
                            <x-ui.sort-header column="group_name" :sort-by="$sortBy" :sort-dir="$sortDir">Group Name</x-ui.sort-header>
                            <th class="px-3 py-2.5 font-medium">Error Reason</th>
                            <th class="px-3 py-2.5 font-medium">Type</th>
                            <th class="px-3 py-2.5 font-medium">Status</th>
                            <th class="px-5 py-2.5 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($rows as $row)
                            @php $temporary = $row->error_type?->isRetryable(); @endphp
                            <tr wire:key="failed-{{ $row->id }}" class="transition hover:bg-canvas/60">
                                <td class="py-3 pl-5 pr-2">
                                    @if ($view === 'failed')
                                        <input type="checkbox" wire:model="selected" value="{{ $row->id }}" x-on:change="$wire.selectAll = false"
                                            aria-label="Select {{ $row->group_name }}" class="size-4 accent-primary">
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-3 py-3">
                                    <span class="block font-medium">{{ $row->updated_at->format('d M Y') }}</span>
                                    <span class="block text-xs text-muted">{{ $row->updated_at->format('g:i A') }}</span>
                                </td>
                                <td class="w-[24%] max-w-0 px-3 py-3">
                                    <button type="button" wire:click="openDetails({{ $row->id }})" class="block w-full truncate text-left font-medium hover:text-primary">{{ $row->campaign->title }}</button>
                                    <span class="block truncate text-xs text-muted">{{ \App\Support\WhatsAppFormatter::plain($row->campaign->message) }}</span>
                                </td>
                                <td class="px-3 py-3 font-medium">{{ $row->group_name }}</td>
                                <td class="max-w-[220px] px-3 py-3">
                                    <span class="block truncate text-red-700" title="{{ $row->error_type?->description() }}">{{ $row->error_type?->label() ?? 'Unknown error' }}</span>
                                </td>
                                <td class="px-3 py-3">
                                    @if ($row->error_type === \App\Enums\SendErrorType::Unconfirmed)
                                        <x-ui.badge color="warning">Check first</x-ui.badge>
                                    @elseif ($temporary)
                                        <x-ui.badge color="primary">Temporary</x-ui.badge>
                                    @else
                                        <x-ui.badge color="danger">Needs fix</x-ui.badge>
                                    @endif
                                </td>
                                <td class="px-3 py-3">
                                    @if ($row->status === SendStatus::Failed)
                                        <x-ui.badge color="danger" dot>Failed</x-ui.badge>
                                    @elseif ($row->status === SendStatus::Skipped)
                                        <x-ui.badge dot>Resolved</x-ui.badge>
                                    @else
                                        <x-ui.badge color="primary" dot>Retrying</x-ui.badge>
                                    @endif
                                </td>
                                <td class="px-5 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        <button type="button" wire:click="openDetails({{ $row->id }})" class="rounded-lg p-1.5 text-muted hover:bg-canvas hover:text-ink" title="Details" aria-label="Details for {{ $row->group_name }}">
                                            <x-lucide-eye class="size-4" />
                                        </button>
                                        @if ($row->status === SendStatus::Failed)
                                            <x-ui.button variant="secondary" size="sm" icon="refresh-cw" wire:click="retry({{ $row->id }})" wire:loading.attr="disabled" wire:target="retry({{ $row->id }})">Retry</x-ui.button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{ $rows->links() }}
    </x-ui.card>

    {{-- ============ Details drawer ============ --}}
    @php $active = $this->active; @endphp
    <x-ui.drawer :name="\App\Livewire\FailedMessages\Index::DRAWER" title="Message Details" width="max-w-lg">
        @if ($active)
            @php $error = $active->error_type; @endphp
            <div x-data="{ tab: 'error' }">
                <div class="mb-4">
                    <p class="text-[15px] font-semibold">{{ $active->campaign->title }}</p>
                    <p class="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted">
                        {{ $active->updated_at->format('d M Y, g:i A') }}
                        @if ($active->status === SendStatus::Failed)
                            <x-ui.badge color="danger" dot>Failed</x-ui.badge>
                        @elseif ($active->status === SendStatus::Skipped)
                            <x-ui.badge dot>Resolved</x-ui.badge>
                        @else
                            <x-ui.badge color="primary" dot>Retrying</x-ui.badge>
                        @endif
                        <span>· {{ $active->attempts }} {{ Str::plural('attempt', $active->attempts) }}</span>
                    </p>
                </div>

                <div class="mb-4 flex rounded-xl bg-canvas p-1 text-[13px]" role="tablist">
                    @foreach (['message' => 'Message', 'error' => 'Error Details', 'group' => 'Group Info'] as $key => $label)
                        <button type="button" role="tab" x-on:click="tab = '{{ $key }}'" :aria-selected="tab === '{{ $key }}'"
                            :class="tab === '{{ $key }}' ? 'bg-white text-ink shadow-sm' : 'text-muted hover:text-ink'"
                            class="flex-1 rounded-lg px-3 py-1.5 font-medium transition">{{ $label }}</button>
                    @endforeach
                </div>

                {{-- Message --}}
                <div x-show="tab === 'message'" x-cloak>
                    <x-ui.whatsapp-preview :text="$active->campaign->message" :attachment="$active->campaign->attachment" />
                    <a href="{{ route('campaigns.show', $active->campaign) }}" class="mt-3 inline-flex items-center gap-1 text-[13px] font-medium text-primary hover:underline">
                        Open campaign <x-lucide-arrow-right class="size-3.5" />
                    </a>
                </div>

                {{-- Error details --}}
                <div x-show="tab === 'error'">
                    <div class="rounded-xl border border-red-200 bg-danger-soft p-4">
                        <p class="flex items-center gap-2 font-semibold text-red-800"><x-lucide-circle-alert class="size-4" /> {{ $error?->label() ?? 'Unknown error' }}</p>
                        <p class="mt-1.5 text-[13px] text-red-800/90">{{ $error?->description() ?? 'No details were recorded.' }}</p>
                    </div>
                    @if ($error === \App\Enums\SendErrorType::Unconfirmed)
                        <p class="mt-3 flex gap-2 rounded-xl bg-warning-soft px-3 py-2.5 text-xs text-amber-800">
                            <x-lucide-triangle-alert class="mt-px size-3.5 shrink-0" />
                            Open “{{ $active->group_name }}” in WhatsApp first. If the message is already there, choose Skip so the group does not get it twice.
                        </p>
                    @endif
                    @if ($this->technical)
                        <details class="mt-3 rounded-xl border border-line px-3 py-2 text-xs">
                            <summary class="cursor-pointer select-none font-medium text-muted">Technical details</summary>
                            <pre class="mt-2 whitespace-pre-wrap break-all font-mono text-[11px] text-slate-600">{{ $this->technical }}</pre>
                        </details>
                    @endif
                </div>

                {{-- Group info --}}
                <div x-show="tab === 'group'" x-cloak>
                    <dl class="divide-y divide-line text-[13px]">
                        @foreach ([
                            ['Group Name', $active->group_name],
                            ['Group ID', $active->group?->whatsapp_identifier ?: ($active->group_id ? '#'.$active->group_id.' (not linked to WhatsApp yet)' : 'Deleted from the app')],
                            ['Category', $active->group?->category?->name ?? '—'],
                            ['Members', $active->group?->member_count ?? '—'],
                            ['Message Type', $active->campaign->attachment?->isImage() ? 'Text + Image' : ($active->campaign->attachment_name ? 'Text + PDF' : 'Text')],
                            ['File Name', $active->campaign->attachment_name ?? '—'],
                            ['File Size', $active->campaign->attachment?->humanSize() ?? '—'],
                            ['Sent At', $active->sent_at?->format('d M Y, g:i A') ?? 'Not sent'],
                            ['Status', $active->status->label()],
                        ] as [$label, $value])
                            <div class="flex justify-between gap-4 py-2.5 first:pt-0">
                                <dt class="text-muted">{{ $label }}</dt>
                                <dd class="text-right font-medium break-all">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    @if ($active->group_id)
                        <a href="{{ route('groups.show', $active->group_id) }}" class="mt-3 inline-flex items-center gap-1 text-[13px] font-medium text-primary hover:underline">
                            Open group <x-lucide-arrow-right class="size-3.5" />
                        </a>
                    @endif
                </div>

                {{-- Retry options --}}
                @if ($active->status === SendStatus::Failed)
                    <fieldset class="mt-6 space-y-2">
                        <legend class="mb-2 text-[13px] font-semibold">What would you like to do?</legend>
                        @foreach ([
                            ['retry_now', 'Retry Now', 'Try sending again.'],
                            ['retry_after_fix', 'Retry After Fix', 'Use when the underlying problem has been resolved.'],
                            ['skip', 'Skip This Message', 'Mark as resolved and don’t retry.'],
                        ] as [$value, $label, $hint])
                            <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-line px-3.5 py-3 has-checked:border-primary has-checked:bg-primary-soft">
                                <input type="radio" wire:model="drawerAction" value="{{ $value }}" class="mt-0.5 accent-primary">
                                <span>
                                    <span class="block text-sm font-medium">{{ $label }}</span>
                                    <span class="block text-xs text-muted">{{ $hint }}</span>
                                </span>
                            </label>
                        @endforeach
                    </fieldset>
                @endif
            </div>
        @endif

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Close</x-ui.button>
            @if ($active?->status === SendStatus::Failed)
                <x-ui.button icon="refresh-cw" wire:click="applyDrawerAction" wire:loading.attr="disabled" wire:target="applyDrawerAction">
                    <span x-text="$wire.drawerAction === 'skip' ? 'Skip Message' : 'Retry Message'">Retry Message</span>
                </x-ui.button>
            @endif
        </x-slot:footer>
    </x-ui.drawer>
</div>

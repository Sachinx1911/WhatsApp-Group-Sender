@php
    use App\Enums\CampaignStatus;
    use App\Enums\SendStatus;

    $counts = $this->counts;
    $active = $campaign->isActive();
    $processed = $counts['sent'] + $counts['failed'] + $counts['skipped'];
    $percent = $campaign->total_groups ? round($processed / $campaign->total_groups * 100, 1) : 0;
    $remaining = $counts['pending'] + $counts['processing'];
@endphp

<div @if ($active) wire:poll.2s.visible @endif>
    <a href="{{ route('history.index') }}" class="mb-3 inline-flex items-center gap-1.5 text-[13px] text-muted hover:text-primary">
        <x-lucide-arrow-left class="size-4" /> Back to Send History
    </a>

    <x-ui.page-header :title="$active ? 'Sending Message' : 'Campaign Details'" :subtitle="$campaign->title">
        <div class="flex items-center gap-2">
            @if ($campaign->is_test) <x-ui.badge color="accent">Test</x-ui.badge> @endif
            <x-ui.status-badge :status="$campaign->status" />
            @if (! $active && $counts['failed'])
                <x-ui.button size="sm" icon="refresh-cw" wire:click="retryFailed" wire:loading.attr="disabled" wire:target="retryFailed">Retry failed ({{ $counts['failed'] }})</x-ui.button>
            @endif
            @unless ($active)
                <x-ui.button variant="secondary" size="sm" icon="copy" :href="route('send.create', ['campaign' => $campaign->id])">Send again</x-ui.button>
            @endunless
        </div>
    </x-ui.page-header>

    {{-- Banners --}}
    @if ($campaign->status === CampaignStatus::Queued)
        <div class="mb-5 flex items-start gap-3 rounded-card border border-blue-200 bg-primary-soft px-4 py-3 text-sm text-blue-800">
            <x-lucide-clock-3 class="mt-0.5 size-5 shrink-0" />
            <p>
                Waiting in line. Only one campaign sends at a time.
                @if ($this->sendingNow)
                    It starts automatically after
                    <a href="{{ route('campaigns.show', $this->sendingNow) }}" class="font-medium underline">{{ $this->sendingNow->title }}</a>.
                @endif
            </p>
        </div>
    @elseif ($campaign->status === CampaignStatus::Paused)
        <div class="mb-5 flex items-start gap-3 rounded-card border border-amber-200 bg-warning-soft px-4 py-3 text-sm text-amber-800">
            <x-lucide-circle-pause class="mt-0.5 size-5 shrink-0" />
            <div>
                <p class="font-medium">Paused{{ $this->pauseReason ? ': '.$this->pauseReason->label() : '' }}</p>
                <p class="mt-0.5">{{ $this->pauseReason?->description() ?? 'No more groups are sent until you resume.' }}</p>
            </div>
        </div>
    @elseif ($this->stalled)
        <div class="mb-5 flex flex-wrap items-center gap-3 rounded-card border border-amber-200 bg-warning-soft px-4 py-3 text-sm text-amber-800">
            <x-lucide-triangle-alert class="size-5 shrink-0" />
            <p class="flex-1">Nothing has been sent for a while. Make sure the app was started with <b>start.bat</b> so the sender is running.</p>
            <x-ui.button variant="secondary" size="sm" icon="refresh-cw" wire:click="restart">Try again</x-ui.button>
        </div>
    @endif

    {{-- Progress --}}
    <x-ui.card>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-[13px] text-muted">Progress</p>
                <p class="mt-1 text-3xl font-semibold tabular-nums">
                    {{ $processed }} <span class="text-xl text-muted">/ {{ $campaign->total_groups }}</span>
                    <span class="ml-2 text-lg font-medium text-primary">{{ rtrim(rtrim(number_format($percent, 1), '0'), '.') }}%</span>
                </p>
            </div>
            <div class="text-right text-[13px] text-muted">
                @if ($campaign->status === CampaignStatus::Sending && $remaining)
                    Estimated time remaining <b class="block text-base text-ink">{{ \App\Support\SendEstimate::label($remaining) }}</b>
                @elseif ($campaign->completed_at)
                    Finished <b class="block text-base text-ink">{{ $campaign->completed_at->format('d M Y, g:i A') }}</b>
                @elseif ($campaign->started_at)
                    Started <b class="block text-base text-ink">{{ $campaign->started_at->format('d M, g:i A') }}</b>
                @endif
            </div>
        </div>
        <x-ui.progress-bar :value="$percent" class="mt-4" :tone="$campaign->status === CampaignStatus::Paused ? 'warning' : 'primary'" />

        <div class="mt-5 grid grid-cols-2 gap-3 md:grid-cols-4">
            @foreach ([
                ['Sent', $counts['sent'], 'bg-success-soft text-emerald-700', 'circle-check'],
                ['Processing', $counts['processing'], 'bg-primary-soft text-blue-700', 'loader-circle'],
                ['Pending', $counts['pending'], 'bg-warning-soft text-amber-700', 'clock-3'],
                ['Failed', $counts['failed'], 'bg-danger-soft text-red-700', 'triangle-alert'],
            ] as [$label, $value, $tone, $icon])
                <div class="flex items-center gap-3 rounded-xl px-4 py-3 {{ $tone }}">
                    <x-dynamic-component :component="'lucide-'.$icon" :class="$icon === 'loader-circle' && $value ? 'size-5 animate-spin' : 'size-5'" />
                    <div>
                        <p class="text-xs opacity-80">{{ $label }}</p>
                        <p class="text-xl font-semibold tabular-nums">{{ $value }}</p>
                    </div>
                </div>
            @endforeach
        </div>
        @if ($counts['skipped'] || $counts['cancelled'])
            <p class="mt-3 text-xs text-muted">
                {{ $counts['skipped'] ? $counts['skipped'].' skipped' : '' }}{{ $counts['skipped'] && $counts['cancelled'] ? ' · ' : '' }}{{ $counts['cancelled'] ? $counts['cancelled'].' not sent (cancelled)' : '' }}
            </p>
        @endif

        @if ($active)
            <div class="mt-5 flex flex-wrap justify-end gap-2 border-t border-line pt-4">
                @if ($campaign->status === CampaignStatus::Sending)
                    <x-ui.button variant="secondary" icon="pause" wire:click="pause" wire:loading.attr="disabled" wire:target="pause">Pause</x-ui.button>
                @elseif ($campaign->status === CampaignStatus::Paused)
                    <x-ui.button icon="play" wire:click="resume" wire:loading.attr="disabled" wire:target="resume">Resume</x-ui.button>
                @endif
                <x-ui.button variant="danger" icon="circle-stop" x-on:click="$dispatch('open-modal', '{{ \App\Livewire\Campaigns\Show::CANCEL_MODAL }}')">Cancel</x-ui.button>
            </div>
        @endif
    </x-ui.card>

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
        {{-- Groups --}}
        <x-ui.card title="Groups" :padding="false" class="min-w-0">
            <x-slot:actions>
                <div class="flex rounded-xl border border-line p-0.5 text-[13px]" role="group" aria-label="Filter groups">
                    @foreach (['' => 'All', 'pending' => 'Pending', 'sent' => 'Sent', 'failed' => 'Not sent'] as $value => $label)
                        <button type="button" wire:click="$set('filter', '{{ $value }}')" aria-pressed="{{ $filter === $value ? 'true' : 'false' }}"
                            @class(['rounded-lg px-2.5 py-1 transition', 'bg-primary text-white' => $filter === $value, 'text-muted hover:text-ink' => $filter !== $value])>{{ $label }}</button>
                    @endforeach
                </div>
            </x-slot:actions>

            @if ($rows->isEmpty())
                <x-ui.empty-state icon="users" title="No groups in this view" />
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[680px] text-left text-[13px]">
                        <thead class="bg-canvas text-xs text-muted">
                            <tr>
                                <th class="px-5 py-2.5 font-medium">Group</th>
                                <th class="px-3 py-2.5 text-right font-medium">Members</th>
                                <th class="px-3 py-2.5 font-medium">Status</th>
                                <th class="px-3 py-2.5 font-medium">Time</th>
                                <th class="px-3 py-2.5 font-medium">Error</th>
                                <th class="px-5 py-2.5 text-right font-medium">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($rows as $row)
                                <tr wire:key="row-{{ $row->id }}" @class(['bg-primary-soft/40' => $row->status === SendStatus::Processing])>
                                    <td class="px-5 py-2.5 font-medium">{{ $row->group_name }}</td>
                                    <td class="px-3 py-2.5 text-right tabular-nums text-muted">{{ $row->group?->member_count ?? '—' }}</td>
                                    <td class="px-3 py-2.5"><x-ui.status-badge :status="$row->status" /></td>
                                    <td class="whitespace-nowrap px-3 py-2.5 text-muted">
                                        {{ $row->sent_at?->format('g:i:s A') ?? ($row->status === SendStatus::Pending ? '—' : $row->updated_at->format('g:i:s A')) }}
                                    </td>
                                    <td class="max-w-[260px] px-3 py-2.5">
                                        @if ($row->error_type && $row->status !== SendStatus::Sent)
                                            <span class="block truncate text-red-700" title="{{ $row->error_type->description() }}">
                                                {{ $row->error_type->label() }}{{ $row->status === SendStatus::Pending ? ' — will retry' : '' }}
                                            </span>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-2.5 text-right">
                                        @if ($row->group_id)
                                            <a href="{{ route('groups.show', $row->group_id) }}" class="font-medium text-primary hover:underline">View group</a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($rows->hasPages())
                    <div class="flex items-center justify-between border-t border-line px-5 py-3 text-[13px] text-muted">
                        <span>{{ $rows->firstItem() }}–{{ $rows->lastItem() }} of {{ $rows->total() }}</span>
                        <div class="flex gap-2">
                            <x-ui.button variant="secondary" size="sm" wire:click="previousPage('rowsPage')" :disabled="$rows->onFirstPage()">Previous</x-ui.button>
                            <x-ui.button variant="secondary" size="sm" wire:click="nextPage('rowsPage')" :disabled="! $rows->hasMorePages()">Next</x-ui.button>
                        </div>
                    </div>
                @endif
            @endif
        </x-ui.card>

        {{-- Message --}}
        <div class="space-y-6">
            <x-ui.card title="Message">
                <x-ui.whatsapp-preview :text="$campaign->message" :attachment="$campaign->attachment" />
                @if (! $campaign->attachment && $campaign->attachment_name)
                    <p class="mt-2 flex items-center gap-1.5 text-xs text-muted"><x-lucide-paperclip class="size-3.5" /> {{ $campaign->attachment_name }} (deleted from the library)</p>
                @endif
            </x-ui.card>
            <x-ui.card title="Timeline">
                <ol class="relative space-y-4 border-l border-line pl-5">
                    @foreach ($this->timeline as $event)
                        <li class="relative">
                            <span @class([
                                'absolute -left-[26.5px] top-1 size-3 rounded-full ring-4 ring-white',
                                'bg-success' => $event['tone'] === 'success',
                                'bg-primary' => $event['tone'] === 'primary',
                                'bg-warning' => $event['tone'] === 'warning',
                                'bg-danger' => $event['tone'] === 'danger',
                                'bg-slate-300' => ! in_array($event['tone'], ['success', 'primary', 'warning', 'danger']),
                            ])></span>
                            <p class="text-[13px] font-medium">{{ $event['label'] }}</p>
                            <p class="text-xs text-muted">{{ $event['at']->format('d M Y, g:i:s A') }}</p>
                        </li>
                    @endforeach
                </ol>
            </x-ui.card>

            <x-ui.card title="Details">
                <dl class="divide-y divide-line text-[13px]">
                    @foreach ([
                        ['Created', $campaign->created_at->format('d M Y, g:i A')],
                        ['Started', $campaign->started_at?->format('d M Y, g:i A') ?? 'Not yet'],
                        ['Groups', $campaign->total_groups],
                        ['Pause between groups', config('educationhub.sending.delay_seconds').' seconds'],
                    ] as [$label, $value])
                        <div class="flex justify-between gap-3 py-2 first:pt-0 last:pb-0">
                            <dt class="text-muted">{{ $label }}</dt><dd class="font-medium">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-ui.card>
        </div>
    </div>

    {{-- Cancel confirmation (only while the campaign can still be cancelled) --}}
    @if ($active)
    <x-ui.modal :name="\App\Livewire\Campaigns\Show::CANCEL_MODAL" title="Cancel this campaign?">
        <div class="flex gap-4">
            <span class="grid size-10 shrink-0 place-items-center rounded-full bg-danger-soft text-danger"><x-lucide-circle-stop class="size-5" /></span>
            <div class="text-sm">
                <p><b>{{ $remaining }}</b> {{ Str::plural('group', $remaining) }} will not receive this message. Groups already sent to keep it.</p>
                <p class="mt-2 text-muted">This cannot be undone. You can send the message again later as a new campaign.</p>
            </div>
        </div>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Keep sending</x-ui.button>
            <x-ui.button variant="danger" icon="circle-stop" wire:click="cancel" wire:loading.attr="disabled" wire:target="cancel">Cancel campaign</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
    @endif
</div>

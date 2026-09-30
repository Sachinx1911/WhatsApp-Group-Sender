<div>
    <a href="{{ route('groups.index') }}" class="mb-3 inline-flex items-center gap-1.5 text-[13px] text-muted hover:text-primary">
        <x-lucide-arrow-left class="size-4" /> Back to Group Manager
    </a>

    <x-ui.page-header :title="$group->name" :subtitle="$group->category->name.' · Added '.$group->created_at->format('d M Y')">
        <x-ui.button variant="secondary" icon="pencil" x-on:click="$dispatch('edit-group', { id: {{ $group->id }} })">Edit</x-ui.button>
        <x-ui.button variant="secondary" :icon="$group->isActive() ? 'circle-pause' : 'circle-play'" wire:click="toggleStatus">
            {{ $group->isActive() ? 'Deactivate' : 'Activate' }}
        </x-ui.button>
        <x-ui.button icon="send" :href="route('send.create', ['groups' => [$group->id]])">Send Message</x-ui.button>
    </x-ui.page-header>

    @php $stats = $this->stats; @endphp
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-ui.stat-card label="Messages Sent" :value="number_format($stats['sent'])" icon="send" tone="success" />
        <x-ui.stat-card label="Failed" :value="number_format($stats['failed'])" icon="triangle-alert" tone="danger" />
        <x-ui.stat-card label="Success Rate" icon="percent" tone="primary"
            :value="$stats['sent'] + $stats['failed'] ? round($stats['sent'] / ($stats['sent'] + $stats['failed']) * 100).'%' : '—'"
            hint="Sent vs failed deliveries" />
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
        {{-- Details --}}
        <x-ui.card title="Group Details">
            <dl class="divide-y divide-line text-[13px]">
                @php $lastMessage = $this->recentSends->first(); @endphp
                @foreach ([
                    ['Group Name', $group->name],
                    ['Category', $group->category->name],
                    ['Members', $group->member_count !== null ? number_format($group->member_count) : 'Not set'],
                    ['WhatsApp Group Identifier', $group->whatsapp_identifier ?: 'Not linked yet'],
                    ['Created Date', $group->created_at->format('d M Y, g:i A')],
                    ['Last Message', $lastMessage?->campaign?->title ?? 'None yet'],
                    ['Last Successful Send', $group->last_sent_at?->format('d M Y, g:i A') ?? 'Never'],
                ] as [$label, $value])
                    <div class="flex justify-between gap-4 py-2.5 first:pt-0 last:pb-0">
                        <dt class="text-muted">{{ $label }}</dt>
                        <dd class="text-right font-medium break-all">{{ $value }}</dd>
                    </div>
                @endforeach
                <div class="flex items-center justify-between gap-4 py-2.5 last:pb-0">
                    <dt class="text-muted">Status</dt>
                    <dd><x-ui.status-badge :status="$group->status" /></dd>
                </div>
            </dl>
        </x-ui.card>

        {{-- Recent sends --}}
        <x-ui.card title="Recent Sends" :subtitle="'Last '.\App\Livewire\Groups\Show::RECENT_SENDS.' messages sent to this group'" :padding="false" class="min-w-0 xl:col-span-2">
            @if ($this->recentSends->isEmpty())
                <x-ui.empty-state icon="send" title="Nothing sent yet">
                    Messages sent to this group will appear here.
                </x-ui.empty-state>
            @else
                <ul class="divide-y divide-line">
                    @foreach ($this->recentSends as $send)
                        <li wire:key="send-{{ $send->id }}" class="flex items-start gap-3 px-5 py-3">
                            <span @class([
                                'mt-0.5 grid size-8 shrink-0 place-items-center rounded-lg',
                                'bg-success-soft text-success' => $send->status === \App\Enums\SendStatus::Sent,
                                'bg-danger-soft text-danger' => $send->status === \App\Enums\SendStatus::Failed,
                                'bg-slate-100 text-muted' => ! in_array($send->status, [\App\Enums\SendStatus::Sent, \App\Enums\SendStatus::Failed]),
                            ])>
                                <x-dynamic-component :component="$send->status === \App\Enums\SendStatus::Failed ? 'lucide-triangle-alert' : 'lucide-send'" class="size-4" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="truncate text-[13px] font-medium">{{ $send->campaign->title }}</span>
                                    @if ($send->campaign->is_test) <x-ui.badge color="accent">Test</x-ui.badge> @endif
                                    <x-ui.status-badge :status="$send->status" />
                                </div>
                                <p class="mt-0.5 truncate text-xs text-muted">{{ \App\Support\WhatsAppFormatter::plain($send->campaign->message) }}</p>
                                @if ($send->error_type)
                                    <p class="mt-1 text-xs text-red-700">{{ $send->error_type->label() }} — {{ $send->error_type->description() }}</p>
                                @endif
                            </div>
                            <span class="shrink-0 whitespace-nowrap text-xs text-muted">{{ ($send->sent_at ?? $send->updated_at)->format('d M, g:i A') }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    </div>

    <livewire:groups.edit-group />
</div>

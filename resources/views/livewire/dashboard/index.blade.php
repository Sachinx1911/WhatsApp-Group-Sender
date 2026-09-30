<div wire:poll.30s.visible="refresh">
    <x-ui.page-header title="Dashboard" subtitle="Overview of your WhatsApp education content distribution">
        <x-ui.button :href="route('send.create')" icon="send">Send Message</x-ui.button>
    </x-ui.page-header>

    @php
        $stats = $this->stats;
        $session = $stats['session'];
        $chart = $this->chart;
        $sentChange = $stats['sent_yesterday'] > 0
            ? (int) round(($stats['sent_today'] - $stats['sent_yesterday']) / $stats['sent_yesterday'] * 100)
            : null;
    @endphp

    {{-- KPI cards --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
        <x-ui.stat-card label="Total Groups" :value="number_format($stats['groups_total'])" icon="users" tone="primary"
            :hint="$stats['groups_active'].' active · '.($stats['groups_total'] - $stats['groups_active']).' inactive'"
            :href="route('groups.index')" />

        <x-ui.stat-card label="Sent Today" :value="number_format($stats['sent_today'])" icon="send" tone="success"
            :hint="$sentChange === null ? 'Nothing sent yesterday' : ($sentChange >= 0 ? '+' : '').$sentChange.'% vs yesterday'" />

        <x-ui.stat-card label="Pending" :value="number_format($stats['pending'])" icon="clock-3" tone="warning"
            :hint="$stats['active_campaigns'] ? $stats['active_campaigns'].' active '.Str::plural('campaign', $stats['active_campaigns']) : 'Nothing in the queue'" />

        <x-ui.stat-card label="Failed" :value="number_format($stats['failed'])" icon="triangle-alert" tone="danger"
            :hint="$stats['failed'] ? 'Need your attention' : 'All clear'" :href="route('failed.index')" />

        <x-ui.stat-card label="WhatsApp Status" :value="$session->status->label()" icon="message-circle"
            :tone="match ($session->status->color()) { 'success' => 'success', 'warning' => 'warning', default => 'danger' }"
            :hint="$session->isConnected() && $session->last_connected_at
                ? 'Connected '.$session->last_connected_at->diffForHumans()
                : 'Connect to start sending'"
            :href="route('settings.index').'#whatsapp'"
            class="sm:col-span-2 lg:col-span-1" />
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
        {{-- Today's Sending Activity --}}
        <x-ui.card title="Today's Sending Activity" :subtitle="now()->format('l, d F Y')" class="min-w-0 xl:col-span-2">
            <x-slot:actions>
                <div class="flex flex-wrap items-center gap-4 text-[13px]">
                    <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-primary"></span>Sent <b class="font-semibold">{{ $chart['totals']['sent'] }}</b></span>
                    <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-danger"></span>Failed <b class="font-semibold">{{ $chart['totals']['failed'] }}</b></span>
                    <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-warning"></span>Pending <b class="font-semibold">{{ $stats['pending'] }}</b></span>
                </div>
            </x-slot:actions>

            @if ($chart['totals']['sent'] + $chart['totals']['failed'] > 0)
                <div wire:ignore wire:key="activity-chart"
                    x-data="activityChart(@js($chart))" x-on:activity-updated.window="update($event.detail)">
                    <div x-ref="canvas" class="-mx-2 h-[280px]" aria-label="Messages sent and failed per hour today"></div>
                </div>
            @else
                <x-ui.empty-state icon="chart-column" title="No sending activity today">
                    Messages you send today will appear here hour by hour.
                </x-ui.empty-state>
            @endif
        </x-ui.card>

        {{-- Quick Actions --}}
        <x-ui.card title="Quick Actions" class="h-full">
            <div class="grid h-full grid-cols-2 grid-rows-2 gap-3">
                @foreach ([
                    ['send.create', 'send', 'Send Message', 'New campaign', 'bg-primary-soft text-primary'],
                    ['groups.index', 'users', 'Manage Groups', 'Batches & categories', 'bg-accent-soft text-accent'],
                    ['templates.index', 'file-text', 'Message Templates', 'Reusable messages', 'bg-success-soft text-success'],
                    ['media.index', 'image', 'Media Library', 'Images & PDFs', 'bg-warning-soft text-warning'],
                ] as [$route, $icon, $label, $caption, $tone])
                    <a href="{{ route($route) }}"
                        class="group flex flex-col justify-between gap-3 rounded-xl border border-line p-4 transition hover:border-primary/30 hover:bg-canvas">
                        <span class="flex items-start justify-between">
                            <span class="grid size-10 place-items-center rounded-xl {{ $tone }}">
                                <x-dynamic-component :component="'lucide-'.$icon" class="size-5" />
                            </span>
                            <x-lucide-arrow-up-right class="size-4 text-slate-300 transition group-hover:text-primary" />
                        </span>
                        <span>
                            <span class="block text-[13px] font-medium">{{ $label }}</span>
                            <span class="block text-xs text-muted">{{ $caption }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
        </x-ui.card>
    </div>

    {{-- Recent Campaigns --}}
    <x-ui.card title="Recent Campaigns" subtitle="Latest messages sent to your groups" :padding="false" class="mt-6">
        <x-slot:actions>
            <x-ui.button :href="route('history.index')" variant="secondary" size="sm">View all</x-ui.button>
        </x-slot:actions>

        @if ($this->recentCampaigns->isEmpty())
            <x-ui.empty-state icon="send" title="No campaigns yet">
                Send your first message to see it here.
                <x-slot:actions>
                    <x-ui.button :href="route('send.create')" icon="send" size="sm">Send Message</x-ui.button>
                </x-slot:actions>
            </x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[900px] text-left text-[13px]">
                    <thead class="bg-canvas text-xs text-muted">
                        <tr>
                            <th class="px-5 py-2.5 font-medium">Campaign</th>
                            <th class="px-3 py-2.5 font-medium">Message</th>
                            <th class="px-3 py-2.5 text-right font-medium">Groups</th>
                            <th class="px-3 py-2.5 text-right font-medium">Sent</th>
                            <th class="px-3 py-2.5 text-right font-medium">Failed</th>
                            <th class="px-3 py-2.5 font-medium">Status</th>
                            <th class="px-3 py-2.5 font-medium">Date</th>
                            <th class="px-5 py-2.5 text-right font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($this->recentCampaigns as $campaign)
                            <tr wire:key="campaign-{{ $campaign->id }}" class="transition hover:bg-canvas/60">
                                <td class="w-[26%] max-w-0 px-5 py-3">
                                    <div class="flex items-center gap-2">
                                        <span class="truncate font-medium">{{ $campaign->title }}</span>
                                        @if ($campaign->is_test)
                                            <x-ui.badge color="accent">Test</x-ui.badge>
                                        @endif
                                    </div>
                                    @if ($campaign->attachment_name)
                                        <span class="mt-0.5 flex items-center gap-1 text-xs text-muted">
                                            <x-lucide-paperclip class="size-3 shrink-0" /><span class="truncate">{{ $campaign->attachment_name }}</span>
                                        </span>
                                    @endif
                                </td>
                                <td class="w-[30%] max-w-0 px-3 py-3 text-muted">
                                    <span class="block truncate">{{ \App\Support\WhatsAppFormatter::plain($campaign->message) }}</span>
                                </td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ $campaign->total_groups }}</td>
                                <td class="px-3 py-3 text-right tabular-nums text-emerald-700">{{ $campaign->sent_count }}</td>
                                <td @class(['px-3 py-3 text-right tabular-nums', 'font-medium text-red-600' => $campaign->failed_count, 'text-muted' => ! $campaign->failed_count])>{{ $campaign->failed_count }}</td>
                                <td class="px-3 py-3"><x-ui.status-badge :status="$campaign->status" /></td>
                                <td class="whitespace-nowrap px-3 py-3 text-muted">{{ $campaign->created_at->format('d M, g:i A') }}</td>
                                <td class="px-5 py-3 text-right">
                                    <a href="{{ route('campaigns.show', $campaign) }}" class="font-medium text-primary hover:underline">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
</div>

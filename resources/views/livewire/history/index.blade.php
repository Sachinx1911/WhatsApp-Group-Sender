<div>
    <x-ui.page-header title="Send History" subtitle="View all previously sent campaigns">
        <x-ui.button icon="send" :href="route('send.create')">Send Message</x-ui.button>
    </x-ui.page-header>

    {{-- KPIs (follow the filters) --}}
    @php $kpis = $this->kpis; @endphp
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-ui.stat-card label="Total Sent" :value="number_format($kpis['campaigns'])" icon="send" tone="primary"
            :hint="Str::plural('campaign', $kpis['campaigns']).($this->hasFilters ? ' matching filters' : '')" />
        <x-ui.stat-card label="Successful" :value="number_format($kpis['sent'])" icon="circle-check" tone="success" hint="Group deliveries" />
        <x-ui.stat-card label="Failed" :value="number_format($kpis['failed'])" icon="triangle-alert" tone="danger" hint="Group deliveries" :href="route('failed.index')" />
        <x-ui.stat-card label="Groups Reached" :value="number_format($kpis['groups_reached'])" icon="users" tone="accent" hint="Different groups" />
    </div>

    <x-ui.card :padding="false" class="mt-6">
        {{-- Filters --}}
        <div class="flex flex-wrap items-center gap-2 border-b border-line p-4">
            <div class="relative min-w-[220px] flex-1">
                <x-lucide-search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
                <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search campaign, message or group" aria-label="Search history"
                    class="w-full rounded-xl border border-line bg-white py-2 pl-9 pr-3 text-sm outline-none focus:border-primary focus:ring-4 focus:ring-primary/10">
            </div>

            <select wire:model.live="range" aria-label="Date range" class="rounded-xl border border-line bg-white py-2 pl-3 pr-8 text-sm outline-none focus:border-primary">
                @foreach (\App\Livewire\History\Index::RANGES as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>

            @if ($range === 'custom')
                <div class="flex items-center gap-1.5 rounded-xl border border-line bg-white px-2.5 py-1 text-sm">
                    <input wire:model.live="from" type="date" aria-label="From date" max="{{ today()->toDateString() }}" class="border-0 bg-transparent py-1 text-sm outline-none">
                    <span class="text-muted">–</span>
                    <input wire:model.live="to" type="date" aria-label="To date" max="{{ today()->toDateString() }}" class="border-0 bg-transparent py-1 text-sm outline-none">
                </div>
            @endif

            <select wire:model.live="category" aria-label="Filter by category" class="rounded-xl border border-line bg-white py-2 pl-3 pr-8 text-sm outline-none focus:border-primary">
                <option value="">All categories</option>
                @foreach ($this->categories as $cat)
                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                @endforeach
            </select>

            <select wire:model.live="status" aria-label="Filter by status" class="rounded-xl border border-line bg-white py-2 pl-3 pr-8 text-sm outline-none focus:border-primary">
                <option value="">All statuses</option>
                @foreach ($this->statusOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>

            <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-line bg-white px-3 py-2 text-sm text-muted">
                <input wire:model.live="hideTests" type="checkbox" class="size-4 accent-primary"> Hide test sends
            </label>

            @if ($this->hasFilters)
                <x-ui.button variant="ghost" size="sm" icon="x" wire:click="clearFilters">Clear filters</x-ui.button>
            @endif
        </div>

        {{-- Table --}}
        @if ($campaigns->isEmpty())
            @if ($this->hasFilters)
                <x-ui.empty-state icon="search-x" title="No campaigns match your filters">
                    Try a different date range, status or search.
                    <x-slot:actions>
                        <x-ui.button variant="secondary" size="sm" wire:click="clearFilters">Clear filters</x-ui.button>
                    </x-slot:actions>
                </x-ui.empty-state>
            @else
                <x-ui.empty-state icon="clock-3" title="Nothing sent yet">
                    Campaigns you send appear here with their results.
                    <x-slot:actions>
                        <x-ui.button size="sm" icon="send" :href="route('send.create')">Send Message</x-ui.button>
                    </x-slot:actions>
                </x-ui.empty-state>
            @endif
        @else
            <div class="overflow-x-auto" wire:loading.class="opacity-60" wire:target="search,category,status,range,from,to,hideTests,sortBy,gotoPage,nextPage,previousPage,perPage">
                <table class="w-full min-w-[1000px] text-left text-[13px]">
                    <thead class="bg-canvas text-xs text-muted">
                        <tr>
                            <x-ui.sort-header column="created_at" :sort-by="$sortBy" :sort-dir="$sortDir" class="pl-5">Date & Time</x-ui.sort-header>
                            <th class="px-3 py-2.5 font-medium">Campaign</th>
                            <th class="px-3 py-2.5 font-medium">Message</th>
                            <x-ui.sort-header column="total_groups" :sort-by="$sortBy" :sort-dir="$sortDir" align="right">Groups</x-ui.sort-header>
                            <x-ui.sort-header column="sent_count" :sort-by="$sortBy" :sort-dir="$sortDir" align="right">Sent</x-ui.sort-header>
                            <x-ui.sort-header column="failed_count" :sort-by="$sortBy" :sort-dir="$sortDir" align="right">Failed</x-ui.sort-header>
                            <th class="px-3 py-2.5 font-medium">Attachment</th>
                            <th class="px-3 py-2.5 font-medium">Status</th>
                            <th class="px-5 py-2.5 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($campaigns as $campaign)
                            <tr wire:key="history-{{ $campaign->id }}" class="cursor-pointer transition hover:bg-canvas/60"
                                x-data x-on:click="if (! $event.target.closest('a, button')) window.location = @js(route('campaigns.show', $campaign))">
                                <td class="whitespace-nowrap py-3 pl-5 pr-3">
                                    <span class="block font-medium">{{ $campaign->created_at->format('d M Y') }}</span>
                                    <span class="block text-xs text-muted">{{ $campaign->created_at->format('g:i A') }}</span>
                                </td>
                                <td class="w-[22%] max-w-0 px-3 py-3">
                                    <span class="flex items-center gap-2">
                                        <a href="{{ route('campaigns.show', $campaign) }}" class="truncate font-medium hover:text-primary">{{ $campaign->title }}</a>
                                        @if ($campaign->is_test) <x-ui.badge color="accent">Test</x-ui.badge> @endif
                                    </span>
                                </td>
                                <td class="w-[26%] max-w-0 px-3 py-3 text-muted">
                                    <span class="block truncate">{{ \App\Support\WhatsAppFormatter::plain($campaign->message) }}</span>
                                </td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ $campaign->total_groups }}</td>
                                <td class="px-3 py-3 text-right tabular-nums text-emerald-700">{{ $campaign->sent_count }}</td>
                                <td @class(['px-3 py-3 text-right tabular-nums', 'font-medium text-red-600' => $campaign->failed_count, 'text-muted' => ! $campaign->failed_count])>{{ $campaign->failed_count }}</td>
                                <td class="max-w-[160px] px-3 py-3">
                                    @if ($campaign->attachment_name)
                                        <span class="flex items-center gap-1 text-muted" title="{{ $campaign->attachment_name }}">
                                            <x-lucide-paperclip class="size-3.5 shrink-0" /><span class="truncate">{{ $campaign->attachment_name }}</span>
                                        </span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3"><x-ui.status-badge :status="$campaign->status" /></td>
                                <td class="px-5 py-3">
                                    <div class="flex items-center justify-end gap-0.5 text-muted">
                                        <a href="{{ route('campaigns.show', $campaign) }}" class="rounded-lg p-1.5 hover:bg-canvas hover:text-ink" title="View details" aria-label="View {{ $campaign->title }}">
                                            <x-lucide-eye class="size-4" />
                                        </a>
                                        <a href="{{ route('send.create', ['campaign' => $campaign->id]) }}" class="rounded-lg p-1.5 hover:bg-canvas hover:text-ink" title="Send again" aria-label="Send {{ $campaign->title }} again">
                                            <x-lucide-copy class="size-4" />
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{ $campaigns->links() }}
    </x-ui.card>
</div>

<div wire:poll.10s.visible class="flex items-center gap-2">
    @if ($activeCampaign)
        {{-- Links to the live progress screen once it exists (Phase 9). --}}
        <a href="{{ route('history.index') }}"
            class="hidden items-center gap-2 whitespace-nowrap rounded-full border border-blue-200 bg-primary-soft px-3 py-1.5 text-[13px] font-medium text-blue-700 transition hover:brightness-95 md:inline-flex"
            title="{{ $activeCampaign->title }}">
            @if ($activeCampaign->status === \App\Enums\CampaignStatus::Sending)
                <x-lucide-loader-circle class="size-3.5 animate-spin" />
            @else
                <x-lucide-pause class="size-3.5" />
            @endif
            {{ $activeCampaign->status->label() }} {{ $activeCampaign->processedCount() }}/{{ $activeCampaign->total_groups }}
        </a>
    @endif

    <x-ui.whatsapp-status :status="$session->status" />
</div>

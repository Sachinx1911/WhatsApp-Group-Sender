<?php

namespace App\Livewire\Layout;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\WhatsAppSession;
use Livewire\Attributes\On;
use Livewire\Component;

/** WhatsApp connection badge and the "Sending 147/250" chip. Polls while visible. */
class HeaderStatus extends Component
{
    /** Settings → Connect / Disconnect / Refresh: update the badge right away. */
    #[On('whatsapp-status-changed')]
    public function refreshStatus(): void {}

    public function render()
    {
        return view('livewire.layout.header-status', [
            'session' => WhatsAppSession::current(),
            'activeCampaign' => Campaign::query()
                ->whereIn('status', [CampaignStatus::Sending, CampaignStatus::Paused, CampaignStatus::Queued])
                ->orderByRaw('case status when ? then 0 when ? then 1 else 2 end', [CampaignStatus::Sending->value, CampaignStatus::Paused->value])
                ->oldest()
                ->first(),
        ]);
    }
}

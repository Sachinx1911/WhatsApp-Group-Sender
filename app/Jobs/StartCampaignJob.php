<?php

namespace App\Jobs;

use App\Enums\CampaignStatus;
use App\Enums\SendStatus;
use App\Models\Campaign;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queues one SendToGroupJob per group still pending (docs/MASTER_PROMPT.md §31).
 * Safe to run more than once: each SendToGroupJob skips groups already handled.
 */
class StartCampaignJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $campaignId)
    {
        $this->onQueue('whatsapp');
    }

    public function handle(): void
    {
        $campaign = Campaign::find($this->campaignId);

        if ($campaign?->status !== CampaignStatus::Sending) {
            return;
        }

        $campaign->campaignGroups()
            ->where('status', SendStatus::Pending)
            ->orderBy('id')
            ->pluck('id')
            ->each(fn (int $rowId) => SendToGroupJob::dispatch($rowId));
    }
}

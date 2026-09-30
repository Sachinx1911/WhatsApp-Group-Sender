<?php

namespace App\Jobs;

use App\Enums\CampaignStatus;
use App\Enums\SendStatus;
use App\Models\Campaign;
use App\Services\Campaigns\CampaignRunner;
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

        $rowIds = $campaign->campaignGroups()
            ->where('status', SendStatus::Pending)
            ->orderBy('id')
            ->pluck('id');

        // Nothing left to send (e.g. paused while the last group was in flight, then
        // resumed): close the campaign now, or it stays "Sending" and blocks every other one.
        if ($rowIds->isEmpty()) {
            app(CampaignRunner::class)->finishIfDone($campaign);

            return;
        }

        $rowIds->each(fn (int $rowId) => SendToGroupJob::dispatch($rowId));
    }
}

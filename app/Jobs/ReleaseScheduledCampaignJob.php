<?php

namespace App\Jobs;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Services\Campaigns\CampaignRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queues a scheduled campaign when its time comes. Dispatched with a delay equal to the
 * chosen time, on the database queue, so it survives restarts: if the PC was off at the
 * time, it runs as soon as the app starts again and the message goes out late, with a
 * notification saying so.
 *
 * The scheduled time travels with the job, so a job left over from an earlier
 * reschedule (or a cancelled campaign) finds a mismatch and does nothing.
 */
class ReleaseScheduledCampaignJob implements ShouldQueue
{
    use Queueable;

    /** Minutes after the scheduled time from which a send counts as "late". */
    public const LATE_AFTER_MINUTES = 10;

    public function __construct(public int $campaignId, public string $scheduledFor)
    {
        $this->onQueue('default');
    }

    public function handle(CampaignRunner $runner): void
    {
        $campaign = Campaign::find($this->campaignId);

        if (! $campaign || $campaign->status !== CampaignStatus::Scheduled) {
            return;
        }

        if ($campaign->scheduled_at?->toIso8601String() !== $this->scheduledFor) {
            return; // rescheduled since; the newer job will release it
        }

        $runner->release($campaign);
    }
}

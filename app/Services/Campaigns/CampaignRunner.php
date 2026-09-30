<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\SendErrorType;
use App\Enums\SendStatus;
use App\Jobs\SendToGroupJob;
use App\Jobs\StartCampaignJob;
use App\Models\AppNotification;
use App\Models\Campaign;
use App\Models\CampaignGroup;
use App\Models\SendLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Campaign lifecycle rules (docs/MASTER_PROMPT.md §31):
 * only one campaign sends at a time, others wait as "queued"; pause / resume / cancel;
 * finishing; and recovery after the PC or the worker was stopped mid-campaign.
 */
class CampaignRunner
{
    private const LOCK = 'campaign-runner';

    /** Start the oldest queued campaign unless one is already sending or paused. */
    public function startNextIfIdle(): ?Campaign
    {
        $next = Cache::lock(self::LOCK, 10)->block(5, function () {
            $busy = Campaign::whereIn('status', [CampaignStatus::Sending, CampaignStatus::Paused])->exists();
            $next = $busy ? null : Campaign::where('status', CampaignStatus::Queued)->orderBy('id')->first();

            $next?->update(['status' => CampaignStatus::Sending, 'started_at' => $next->started_at ?? now(), 'paused_at' => null]);

            return $next;
        });

        if ($next) {
            Log::channel('whatsapp')->info('Campaign started', ['campaign' => $next->id, 'groups' => $next->total_groups, 'test' => $next->is_test]);

            // Dispatched after the lock is released: with a sync queue the job runs right here.
            StartCampaignJob::dispatch($next->id);
        }

        return $next;
    }

    public function pause(Campaign $campaign, ?SendErrorType $reason = null): void
    {
        $paused = Campaign::whereKey($campaign->id)
            ->where('status', CampaignStatus::Sending)
            ->update(['status' => CampaignStatus::Paused, 'paused_at' => now()]);

        if (! $paused) {
            return;
        }

        $campaign->refresh();
        Log::channel('whatsapp')->warning('Campaign paused', ['campaign' => $campaign->id, 'reason' => $reason?->value ?? 'admin']);

        if ($reason) {
            AppNotification::create([
                'type' => $reason === SendErrorType::WhatsAppDisconnected ? 'whatsapp_disconnected' : 'queue_stopped',
                'title' => 'Sending paused: '.$reason->label(),
                'body' => "“{$campaign->title}” was paused. {$reason->description()}",
                'url' => route('campaigns.show', $campaign, absolute: false),
            ]);
        }
    }

    /** Continue a paused campaign — or queue it again if another campaign is sending now. */
    public function resume(Campaign $campaign): void
    {
        // Guarded in the database, not on the loaded model: two Resume clicks, or a Resume
        // racing the queue, must not flip a campaign that already moved on.
        $resumed = Campaign::whereKey($campaign->id)
            ->where('status', CampaignStatus::Paused)
            ->update(['status' => CampaignStatus::Queued, 'paused_at' => null]);

        if (! $resumed) {
            return;
        }

        $campaign->refresh();
        Log::channel('whatsapp')->info('Campaign resumed', ['campaign' => $campaign->id]);

        $this->startNextIfIdle();
    }

    public function cancel(Campaign $campaign): void
    {
        $cancelled = DB::transaction(function () use ($campaign) {
            $cancelled = Campaign::whereKey($campaign->id)
                ->whereIn('status', [CampaignStatus::Queued, CampaignStatus::Sending, CampaignStatus::Paused])
                ->update(['status' => CampaignStatus::Cancelled, 'completed_at' => now(), 'paused_at' => null]);

            if ($cancelled) {
                $campaign->campaignGroups()->where('status', SendStatus::Pending)->update(['status' => SendStatus::Cancelled, 'updated_at' => now()]);
                $campaign->refresh()->refreshCounters();
            }

            return $cancelled;
        });

        if (! $cancelled) {
            return;
        }

        Log::channel('whatsapp')->info('Campaign cancelled', ['campaign' => $campaign->id, 'sent' => $campaign->sent_count]);

        $this->startNextIfIdle();
    }

    /** After each group: update counters and, when nothing is left, close the campaign. */
    public function finishIfDone(Campaign $campaign): void
    {
        $campaign->refresh()->refreshCounters();

        if ($campaign->status !== CampaignStatus::Sending || $campaign->pending_count > 0) {
            return;
        }

        $finished = Campaign::whereKey($campaign->id)
            ->where('status', CampaignStatus::Sending)
            ->update(['status' => $campaign->outcomeStatus(), 'completed_at' => now()]);

        if ($finished) {
            $campaign->refresh();
            Log::channel('whatsapp')->info('Campaign completed', [
                'campaign' => $campaign->id, 'status' => $campaign->status->value,
                'sent' => $campaign->sent_count, 'failed' => $campaign->failed_count,
            ]);
            $this->notifyFinished($campaign);
        }

        $this->startNextIfIdle();
    }

    /**
     * Send failed groups again (admin action from Failed Messages). The rows go back to
     * "pending" with a fresh attempt count; a finished campaign is queued again so it
     * still waits its turn behind the campaign that is sending now.
     *
     * @param  iterable<CampaignGroup>  $rows
     * @return int rows queued for retry
     */
    public function retry(iterable $rows): int
    {
        $queued = 0;

        foreach (collect($rows)->filter(fn (CampaignGroup $r) => $r->status === SendStatus::Failed)->groupBy('campaign_id') as $campaignId => $group) {
            $campaign = Campaign::find($campaignId);

            DB::transaction(function () use ($campaign, $group) {
                CampaignGroup::whereKey($group->pluck('id')->all())
                    ->where('status', SendStatus::Failed)
                    ->update(['status' => SendStatus::Pending, 'attempts' => 0, 'updated_at' => now()]);

                $campaign->refreshCounters();

                if ($campaign->status->isFinished()) {
                    $campaign->update(['status' => CampaignStatus::Queued, 'completed_at' => null]);
                }
            });

            $queued += $group->count();
            Log::channel('whatsapp')->info('Retry requested', ['campaign' => $campaignId, 'groups' => $group->pluck('group_name')->all()]);

            // Already sending: queue just these groups. Otherwise they go out when the campaign starts.
            if ($campaign->fresh()->status === CampaignStatus::Sending) {
                $group->each(fn (CampaignGroup $row) => SendToGroupJob::dispatch($row->id));
            }
        }

        $this->startNextIfIdle();

        return $queued;
    }

    /**
     * Mark failed groups as resolved without sending (admin decided it is not needed).
     *
     * @param  iterable<CampaignGroup>  $rows
     */
    public function skip(iterable $rows): int
    {
        $skipped = 0;

        foreach (collect($rows)->filter(fn (CampaignGroup $r) => $r->status === SendStatus::Failed)->groupBy('campaign_id') as $campaignId => $group) {
            $skipped += CampaignGroup::whereKey($group->pluck('id')->all())->where('status', SendStatus::Failed)->update(['status' => SendStatus::Skipped, 'updated_at' => now()]);

            $campaign = Campaign::find($campaignId)->refreshCounters();

            // A finished campaign's outcome may change, e.g. partially failed → completed.
            if (in_array($campaign->status, [CampaignStatus::Completed, CampaignStatus::PartiallyFailed, CampaignStatus::Failed], true)) {
                $campaign->update(['status' => $campaign->outcomeStatus()]);
            }

            Log::channel('whatsapp')->info('Failures skipped', ['campaign' => $campaignId, 'groups' => $group->pluck('group_name')->all()]);
        }

        return $skipped;
    }

    public function dailyLimitReached(): bool
    {
        $limit = (int) config('educationhub.sending.daily_limit', 0);

        return $limit > 0
            && CampaignGroup::where('status', SendStatus::Sent)->whereDate('sent_at', today())->count() >= $limit;
    }

    /**
     * Run when the app starts: groups left "processing" by an interrupted send are
     * marked "Delivery unconfirmed" (never re-sent automatically, to avoid duplicates),
     * sending campaigns are picked up again, and the next queued campaign starts.
     *
     * At start-up nothing can legitimately be "processing" (the queue is not running yet),
     * so every such row is interrupted, however recent. The age cutoff only applies when
     * recovering while the queue is live, where a row may still be mid-send.
     *
     * @return array{unconfirmed: int, restarted: int}
     */
    public function recover(bool $queueIsRunning = false): array
    {
        $stuck = CampaignGroup::where('status', SendStatus::Processing)
            ->when($queueIsRunning, fn ($q) => $q->where('updated_at', '<', now()->subMinutes((int) config('educationhub.whatsapp.stuck_after_minutes', 5))))
            ->get();

        foreach ($stuck as $row) {
            $type = SendErrorType::Unconfirmed;
            $row->update(['status' => SendStatus::Failed, 'error_type' => $type, 'error_message' => $type->label()]);
            SendLog::create([
                'campaign_id' => $row->campaign_id, 'group_id' => $row->group_id, 'group_name' => $row->group_name,
                'status' => SendStatus::Failed, 'message' => "Sending to {$row->group_name} was interrupted",
                'error_type' => $type, 'error_message' => $type->description(),
                'technical_details' => 'Row was still processing at '.$row->updated_at?->toDateTimeString().' when the app restarted.',
            ]);
            Log::channel('whatsapp')->warning('Unconfirmed send after restart', ['campaign' => $row->campaign_id, 'group' => $row->group_name]);
        }

        $sending = Campaign::where('status', CampaignStatus::Sending)->get();

        foreach ($sending as $campaign) {
            $campaign->refreshCounters();

            if ($campaign->pending_count > 0) {
                StartCampaignJob::dispatch($campaign->id);
            } else {
                $this->finishIfDone($campaign);
            }
        }

        $this->startNextIfIdle();

        return ['unconfirmed' => $stuck->count(), 'restarted' => $sending->where('pending_count', '>', 0)->count()];
    }

    private function notifyFinished(Campaign $campaign): void
    {
        [$type, $title, $body] = match ($campaign->status) {
            CampaignStatus::Completed => ['campaign_completed', 'Campaign completed', "“{$campaign->title}” was sent to {$campaign->sent_count} ".str('group')->plural($campaign->sent_count).'.'],
            CampaignStatus::Failed => ['campaign_failures', 'Campaign failed', "No group received “{$campaign->title}”."],
            default => ['campaign_failures', 'Some messages failed', "{$campaign->failed_count} of {$campaign->total_groups} groups failed in “{$campaign->title}”."],
        };

        AppNotification::create(['type' => $type, 'title' => $title, 'body' => $body, 'url' => route('campaigns.show', $campaign, absolute: false)]);
    }
}

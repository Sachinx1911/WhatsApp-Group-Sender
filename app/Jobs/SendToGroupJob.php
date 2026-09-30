<?php

namespace App\Jobs;

use App\Enums\CampaignStatus;
use App\Enums\SendErrorType;
use App\Enums\SendStatus;
use App\Enums\WhatsAppConnectionStatus;
use App\Models\CampaignGroup;
use App\Models\SendLog;
use App\Services\Campaigns\CampaignRunner;
use App\Services\WhatsApp\SendResult;
use App\Services\WhatsApp\WhatsAppServiceInterface;
use App\Services\WhatsApp\WhatsAppSessionManager;
use App\Support\Settings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends the campaign message to ONE group (docs/MASTER_PROMPT.md §31).
 *
 * Duplicate protection: the row is claimed atomically (pending → processing) before
 * sending, and any row that is not pending is left alone. A row interrupted while
 * processing is never re-sent automatically (see CampaignRunner::recover()).
 */
class SendToGroupJob implements ShouldQueue
{
    use Queueable;

    /** Queue attempts, including releases for automatic retries (the row itself allows max_attempts). */
    public int $tries = 6;

    /** Seconds. Keep below queue.connections.database.retry_after. */
    public int $timeout = 180;

    public function __construct(public int $campaignGroupId)
    {
        $this->onQueue('whatsapp');
    }

    public function handle(WhatsAppServiceInterface $whatsapp, CampaignRunner $runner, WhatsAppSessionManager $sessions): void
    {
        // The worker is long-running: pick up changes made in Settings (delay, daily limit...).
        Settings::apply();

        $row = CampaignGroup::with(['campaign.attachment', 'group'])->find($this->campaignGroupId);

        // Paused / cancelled campaigns and rows already handled are skipped (resume queues them again).
        if (! $row || $row->status !== SendStatus::Pending || $row->campaign->status !== CampaignStatus::Sending) {
            return;
        }

        if ($runner->dailyLimitReached()) {
            $runner->pause($row->campaign, SendErrorType::DailyLimitReached);

            return;
        }

        $claimed = CampaignGroup::whereKey($row->id)
            ->where('status', SendStatus::Pending)
            ->update(['status' => SendStatus::Processing, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);

        if (! $claimed) {
            return;
        }

        $row->refresh();
        $campaign = $row->campaign;
        Log::channel('whatsapp')->info('Sending to group', ['campaign' => $campaign->id, 'group' => $row->group_name, 'attempt' => $row->attempts]);

        try {
            $result = $row->group
                ? $whatsapp->sendToGroup($row->group, $campaign->message, $campaign->attachment)
                : SendResult::failed($row->group_name, SendErrorType::GroupNotFound, 'The group was deleted from the app.');
        } catch (Throwable $e) {
            report($e);
            $result = SendResult::failed($row->group_name, SendErrorType::BrowserError, technical: $e->getMessage());
        }

        // Keep the header badge honest: a send tells us whether WhatsApp Web is really connected.
        if ($result->success && ! $sessions->session()->isConnected()) {
            $sessions->record(WhatsAppConnectionStatus::Connected, 'send');
        }

        $this->record($row, $result, $runner, $sessions);
    }

    private function record(CampaignGroup $row, SendResult $result, CampaignRunner $runner, WhatsAppSessionManager $sessions): void
    {
        $campaign = $row->campaign;

        if ($result->success) {
            $row->update(['status' => SendStatus::Sent, 'sent_at' => now(), 'error_type' => null, 'error_message' => null]);
            $row->group?->update(['last_sent_at' => now()]);
            $this->log($row, SendStatus::Sent, 'Message sent successfully');
            Log::channel('whatsapp')->info('Sent', ['campaign' => $campaign->id, 'group' => $row->group_name]);

            $runner->finishIfDone($campaign);
            $this->waitBeforeNextGroup();

            return;
        }

        $type = $result->errorType;
        $this->log($row, SendStatus::Failed, "Unable to send to {$row->group_name}", $result);
        Log::channel('whatsapp')->warning('Send failed', [
            'campaign' => $campaign->id, 'group' => $row->group_name, 'error' => $type->value, 'attempt' => $row->attempts,
        ]);

        // Connection problems: nothing is wrong with this group. Put it back and pause everything.
        if ($type->pausesCampaign()) {
            $row->update(['status' => SendStatus::Pending, 'attempts' => max(0, $row->attempts - 1)]);

            if ($type === SendErrorType::WhatsAppDisconnected) {
                $sessions->record(WhatsAppConnectionStatus::Disconnected, 'send', $result->technicalDetails); // pauses + notifies
            }

            $runner->pause($campaign, $type);

            return;
        }

        $maxAttempts = (int) config('educationhub.whatsapp.max_attempts', 3);

        if ($type->isRetryable() && $row->attempts < $maxAttempts) {
            $row->update(['status' => SendStatus::Pending, 'error_type' => $type, 'error_message' => $result->errorMessage]);
            $backoff = (array) config('educationhub.whatsapp.retry_backoff', [30, 120]);
            $this->release((int) ($backoff[$row->attempts - 1] ?? end($backoff) ?: 0));

            return;
        }

        $row->update(['status' => SendStatus::Failed, 'error_type' => $type, 'error_message' => $result->errorMessage]);
        $runner->finishIfDone($campaign);
        $this->waitBeforeNextGroup();
    }

    /** The job itself crashed (e.g. killed by the timeout): record it so the campaign can finish. */
    public function failed(?Throwable $exception): void
    {
        $row = CampaignGroup::with('campaign')->find($this->campaignGroupId);

        if (! $row || $row->status->isFinal() || $row->status === SendStatus::Failed) {
            return;
        }

        $type = $row->status === SendStatus::Processing ? SendErrorType::Unconfirmed : SendErrorType::Unknown;
        $row->update(['status' => SendStatus::Failed, 'error_type' => $type, 'error_message' => $type->label()]);
        $this->log($row, SendStatus::Failed, "Unable to send to {$row->group_name}",
            SendResult::failed($row->group_name, $type, technical: $exception?->getMessage()));

        app(CampaignRunner::class)->finishIfDone($row->campaign);
    }

    private function log(CampaignGroup $row, SendStatus $status, string $message, ?SendResult $result = null): void
    {
        SendLog::create([
            'campaign_id' => $row->campaign_id,
            'group_id' => $row->group_id,
            'group_name' => $row->group_name,
            'status' => $status,
            'message' => $message,
            'error_type' => $result?->errorType,
            'error_message' => $result?->errorType?->description(),
            'technical_details' => $result?->technicalDetails ?? ($result && ! $result->success ? $result->errorMessage : null),
        ]);
    }

    /** Fixed, conservative pause between two groups (no randomisation). */
    private function waitBeforeNextGroup(): void
    {
        if ($seconds = (int) config('educationhub.sending.delay_seconds', 0)) {
            sleep($seconds);
        }
    }
}

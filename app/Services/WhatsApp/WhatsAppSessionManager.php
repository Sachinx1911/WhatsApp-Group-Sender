<?php

namespace App\Services\WhatsApp;

use App\Enums\CampaignStatus;
use App\Enums\SendErrorType;
use App\Enums\WhatsAppConnectionStatus;
use App\Models\AppNotification;
use App\Models\Campaign;
use App\Models\WhatsAppSession;
use App\Services\Campaigns\CampaignRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the whatsapp_sessions row in step with the WhatsApp Web worker
 * (docs/MASTER_PROMPT.md §14). Everything that changes the connection goes through
 * here, so the header badge, the Settings screen and the sending queue agree.
 *
 * The browser profile (cookies, local storage) is never read or stored by Laravel:
 * only the connection state and times.
 */
class WhatsAppSessionManager
{
    public function __construct(
        private WhatsAppServiceInterface $whatsapp,
        private CampaignRunner $runner,
    ) {}

    public function session(): WhatsAppSession
    {
        return WhatsAppSession::current();
    }

    /** Open WhatsApp Web. @throws WorkerUnavailableException */
    public function connect(): WhatsAppSession
    {
        Log::channel('whatsapp')->info('Connect requested by admin');
        $this->record(WhatsAppConnectionStatus::Starting, 'admin');

        try {
            $status = $this->whatsapp->connect();
        } catch (WorkerUnavailableException $e) {
            $this->record(WhatsAppConnectionStatus::Disconnected, 'worker_unavailable');

            throw $e;
        }

        return $this->record($status, 'admin', seen: true);
    }

    /** Log out this linked device. A campaign that is sending is paused first. @throws WorkerUnavailableException */
    public function disconnect(): WhatsAppSession
    {
        Log::channel('whatsapp')->info('Disconnect requested by admin');

        if ($campaign = $this->sendingCampaign()) {
            $this->runner->pause($campaign);
        }

        $this->whatsapp->disconnect();

        return $this->record(WhatsAppConnectionStatus::Disconnected, 'admin', seen: true);
    }

    /** Ask the worker for the real state. @throws WorkerUnavailableException */
    public function refresh(): WhatsAppSession
    {
        return $this->record($this->whatsapp->status(), 'refresh', seen: true);
    }

    /**
     * Store a new state. Called by the admin actions above, by the worker's state
     * reports (/internal/whatsapp/state) and by the queue when WhatsApp drops.
     *
     * Losing an established connection pauses the campaign that is sending and
     * notifies the admin; it is never resumed automatically.
     */
    public function record(WhatsAppConnectionStatus $status, string $source, ?string $reason = null, bool $seen = false): WhatsAppSession
    {
        [$session, $previous] = DB::transaction(function () use ($status, $source, $reason, $seen) {
            $session = WhatsAppSession::current();
            $session = WhatsAppSession::whereKey($session->id)->lockForUpdate()->first();
            $previous = $session->status;

            $session->status = $status;
            $session->last_seen_at = $seen ? now() : $session->last_seen_at;

            if ($status === WhatsAppConnectionStatus::Connected && $previous !== $status) {
                $session->last_connected_at = now();
            }

            if ($previous !== $status) {
                $session->metadata = array_merge($session->metadata ?? [], array_filter([
                    'last_change' => ['from' => $previous?->value, 'to' => $status->value, 'source' => $source, 'at' => now()->toIso8601String()],
                    'reason' => $reason,
                ]));
            }

            $session->save();

            return [$session, $previous];
        });

        if ($previous !== $status) {
            Log::channel('whatsapp')->info('WhatsApp session: '.$status->label(), array_filter([
                'from' => $previous?->value, 'source' => $source, 'reason' => $reason,
            ]));
        }

        if ($previous === WhatsAppConnectionStatus::Connected && $status === WhatsAppConnectionStatus::Disconnected && $source !== 'admin') {
            $this->connectionLost();
        }

        return $session;
    }

    /** The worker reported in: remember when, so the Settings screen can show it is running. */
    public function heartbeat(): void
    {
        WhatsAppSession::current()->forceFill(['last_seen_at' => now()])->save();
    }

    /**
     * Is the WhatsApp Web worker running? The practice driver is built in; the
     * Playwright worker reports in every few seconds.
     */
    public function workerRunning(): bool
    {
        if (config('educationhub.whatsapp.driver') === 'fake') {
            return true;
        }

        $seen = WhatsAppSession::current()->last_seen_at;

        return $seen !== null && $seen->gt(now()->subSeconds((int) config('educationhub.whatsapp.worker.offline_after', 45)));
    }

    /**
     * Is the sending queue worker picking up jobs? Only known while something is
     * waiting: a job that could have started over a minute ago but has not.
     */
    public function queueStalled(): bool
    {
        if (config('queue.default') !== 'database') {
            return false;
        }

        return DB::table(config('queue.connections.database.table', 'jobs'))
            ->where('queue', 'whatsapp')
            ->whereNull('reserved_at')
            ->where('available_at', '<', now()->subMinute()->getTimestamp())
            ->exists();
    }

    private function connectionLost(): void
    {
        if ($campaign = $this->sendingCampaign()) {
            $this->runner->pause($campaign, SendErrorType::WhatsAppDisconnected); // notifies

            return;
        }

        AppNotification::create([
            'type' => 'whatsapp_disconnected',
            'title' => 'WhatsApp disconnected',
            'body' => 'WhatsApp Web was logged out. Connect again from Settings before sending.',
            'url' => route('settings.index', absolute: false),
        ]);
    }

    private function sendingCampaign(): ?Campaign
    {
        return Campaign::where('status', CampaignStatus::Sending)->first();
    }
}

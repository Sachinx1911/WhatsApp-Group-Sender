<?php

namespace App\Services\WhatsApp;

use App\Enums\SendErrorType;
use App\Enums\WhatsAppConnectionStatus;
use App\Models\Group;
use App\Models\Media;
use App\Models\WhatsAppSession;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Talks to the local Node Playwright worker over HTTP (docs/MASTER_PROMPT.md §32):
 * 127.0.0.1:<port>, every request carrying X-Worker-Token. Used when
 * WHATSAPP_DRIVER=playwright. The worker itself owns the visible Chromium window and
 * the actual WhatsApp Web automation; this class only relays requests and results.
 */
class PlaywrightWhatsAppService implements WhatsAppServiceInterface
{
    /**
     * Longest a single send may take before Laravel gives up. Must stay above the worker's
     * own worst case (search retries + attachment preview + delivery confirmation, ~100 s),
     * otherwise Laravel calls a send "lost" while Chromium is still finishing it.
     */
    public const SEND_TIMEOUT = 150;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
    ) {}

    public function status(): WhatsAppConnectionStatus
    {
        $response = $this->safeRequest(fn () => $this->request()->get('/status'));

        return $this->stateFrom($response->json('state'));
    }

    public function connect(): WhatsAppConnectionStatus
    {
        // Launching Chromium, loading WhatsApp Web and waiting for it to settle can take
        // well over half a minute on a slow PC; a short timeout here reads as "worker not
        // running" to the admin even though it is busy opening the window.
        $response = $this->safeRequest(fn () => $this->request(timeout: 90)->post('/connect'));

        return $this->stateFrom($response->json('state'));
    }

    public function disconnect(): void
    {
        $this->safeRequest(fn () => $this->request(timeout: 30)->post('/disconnect'));
    }

    public function sendToGroup(Group $group, string $message, Media|iterable|null $attachment = null): SendResult
    {
        $files = $attachment === null
            ? collect()
            : collect($attachment instanceof Media ? [$attachment] : $attachment)->values();

        $paths = $files->map(fn (Media $media) => Storage::disk('local')->path($media->path))->all();

        try {
            $response = $this->request(timeout: self::SEND_TIMEOUT)->post('/send', array_filter([
                'group' => $group->name,
                'message' => $message,
                // attachment_path is still sent for a single file so an older worker keeps
                // working; attachment_paths carries the whole list.
                'attachment_path' => $paths[0] ?? null,
                'attachment_paths' => $paths,
            ], fn ($value) => $value !== null && $value !== '' && $value !== []));
        } catch (ConnectionException $e) {
            // A refused connection means no worker. A timeout means the worker took the
            // request and may well have sent the message: never put that row back in the
            // queue, or the group gets it twice on resume.
            if ($this->isTimeout($e)) {
                Log::channel('whatsapp')->error('Worker did not answer in time during send', ['group' => $group->name, 'error' => $e->getMessage()]);

                return SendResult::failed($group->name, SendErrorType::Unconfirmed, technical: $e->getMessage());
            }

            Log::channel('whatsapp')->error('Worker unreachable during send', ['group' => $group->name, 'error' => $e->getMessage()]);

            return SendResult::failed($group->name, SendErrorType::WorkerUnavailable);
        }

        $this->touch();

        if ($response->status() === 409) {
            return SendResult::busy($group->name);
        }

        if ($response->failed()) {
            return SendResult::failed($group->name, SendErrorType::WorkerUnavailable, technical: "HTTP {$response->status()}");
        }

        return SendResult::fromArray($response->json() ?? []);
    }

    /**
     * Chat names from the linked WhatsApp account, for "Sync from WhatsApp".
     *
     * $scope "groups" reads WhatsApp's own Groups filter so personal chats are left out;
     * "all" reads every chat. Reading a long list means scrolling it, so allow more time.
     */
    public function listGroupNames(string $scope = 'groups'): array
    {
        $response = $this->safeRequest(fn () => $this->request(timeout: 120)->get('/groups', ['scope' => $scope]));

        if ($response->json('success') !== true) {
            throw WorkerUnavailableException::refused($response->json('error_message'));
        }

        return $response->json('groups') ?? [];
    }

    /**
     * Member count per group name, or null for any group WhatsApp would not show one for.
     *
     * The worker opens each group's info panel in turn, so allow roughly four seconds per
     * name before the request is considered lost.
     *
     * @param  array<int, string>  $names
     * @return array<string, int|null>
     */
    public function memberCounts(array $names): array
    {
        if ($names === []) {
            return [];
        }

        $timeout = min(600, 30 + count($names) * 8);

        $response = $this->safeRequest(fn () => $this->request(timeout: $timeout)->post('/member-counts', ['names' => array_values($names)]));

        if ($response->json('success') !== true) {
            throw WorkerUnavailableException::refused($response->json('error_message'));
        }

        return $response->json('counts') ?? [];
    }

    private function request(int $timeout = 10): PendingRequest
    {
        // PHP kills a web request after max_execution_time (30 s by default), which is
        // shorter than a sync or connect call to the worker. Give this request room to
        // wait for the answer; the HTTP timeout below is still the real limit.
        if (! app()->runningInConsole()) {
            @set_time_limit($timeout + 30);
        }

        return Http::baseUrl($this->baseUrl)
            ->withHeaders(['X-Worker-Token' => $this->token])
            ->timeout($timeout);
    }

    /**
     * Run an HTTP call to the worker, turning an unreachable worker (connection refused,
     * DNS, timeout) or a non-2xx response into WorkerUnavailableException so callers
     * only need to handle one failure mode for "the worker isn't there".
     */
    private function safeRequest(\Closure $call): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException $e) {
            throw WorkerUnavailableException::make($e->getMessage());
        }

        $this->touch();

        if ($response->status() === 409) {
            throw WorkerUnavailableException::refused($response->json('error_message') ?: 'The worker is busy sending a message. Pause the campaign first.');
        }

        if ($response->failed()) {
            throw WorkerUnavailableException::make("HTTP {$response->status()}");
        }

        return $response;
    }

    /**
     * Any answer from the worker proves it is alive. Its own heartbeats can be starved
     * while Laravel's single dev-server process is busy with a long worker call, so this
     * keeps "worker running" honest during a sync or a campaign.
     */
    private function touch(): void
    {
        try {
            WhatsAppSession::current()->forceFill(['last_seen_at' => now()])->save();
        } catch (\Throwable) {
            // Liveness display only; never let it break the request that just succeeded.
        }
    }

    /** cURL reports a read timeout as errno 28; a dead worker refuses the connection instead. */
    private function isTimeout(ConnectionException $e): bool
    {
        return (bool) preg_match('/timed? ?out|cURL error 28/i', $e->getMessage());
    }

    private function stateFrom(?string $state): WhatsAppConnectionStatus
    {
        return WhatsAppConnectionStatus::tryFrom(strtolower((string) $state)) ?? WhatsAppConnectionStatus::Disconnected;
    }
}

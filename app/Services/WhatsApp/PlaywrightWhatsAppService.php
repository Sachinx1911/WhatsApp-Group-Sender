<?php

namespace App\Services\WhatsApp;

use App\Enums\SendErrorType;
use App\Enums\WhatsAppConnectionStatus;
use App\Models\Group;
use App\Models\Media;
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
        // Opening WhatsApp Web and (when needed) waiting for a QR scan can take a while.
        $response = $this->safeRequest(fn () => $this->request(timeout: 30)->post('/connect'));

        return $this->stateFrom($response->json('state'));
    }

    public function disconnect(): void
    {
        $this->safeRequest(fn () => $this->request(timeout: 15)->post('/disconnect'));
    }

    public function sendToGroup(Group $group, string $message, ?Media $attachment = null): SendResult
    {
        try {
            $response = $this->request(timeout: 45)->post('/send', array_filter([
                'group' => $group->name,
                'message' => $message,
                'attachment_path' => $attachment ? Storage::disk('local')->path($attachment->path) : null,
            ]));
        } catch (ConnectionException $e) {
            Log::channel('whatsapp')->error('Worker unreachable during send', ['group' => $group->name, 'error' => $e->getMessage()]);

            return SendResult::failed($group->name, SendErrorType::WorkerUnavailable);
        }

        if ($response->status() === 409) {
            return SendResult::failed($group->name, SendErrorType::Unknown, technical: 'Worker busy with another send.');
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
            throw WorkerUnavailableException::make($response->json('error_message'));
        }

        return $response->json('groups') ?? [];
    }

    private function request(int $timeout = 10): PendingRequest
    {
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

        if ($response->failed()) {
            throw WorkerUnavailableException::make("HTTP {$response->status()}");
        }

        return $response;
    }

    private function stateFrom(?string $state): WhatsAppConnectionStatus
    {
        return WhatsAppConnectionStatus::tryFrom(strtolower((string) $state)) ?? WhatsAppConnectionStatus::Disconnected;
    }
}

<?php

namespace App\Services\WhatsApp;

use App\Enums\SendErrorType;
use App\Enums\WhatsAppConnectionStatus;
use App\Models\Group;
use App\Models\Media;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Pretends to send. Nothing leaves this computer. Used by tests and while the
 * Playwright worker does not exist yet (WHATSAPP_DRIVER=fake).
 *
 * Failures can be simulated per group name through config
 * (educationhub.whatsapp.fake.failures, e.g. ['Police Batch 04' => 'NOT_MEMBER']),
 * or in tests with failFor() / disconnect().
 *
 * Connect/Disconnect are simulated instantly (no QR code). The state is kept in the
 * cache so the web app and the queue worker (separate processes) agree on it.
 */
class FakeWhatsAppService implements WhatsAppServiceInterface
{
    private const STATE_KEY = 'whatsapp.fake.connected';

    /** @var array<string, SendErrorType> group name => error */
    private array $failures = [];

    /** @var array<int, array{group: string, message: string, attachment: ?string}> */
    public array $sent = [];

    public function __construct()
    {
        foreach ((array) config('educationhub.whatsapp.fake.failures', []) as $group => $type) {
            $this->failures[$group] = SendErrorType::from($type);
        }
    }

    public function failFor(string $groupName, SendErrorType $type): static
    {
        $this->failures[$groupName] = $type;

        return $this;
    }

    /** Answer "busy" (like the real worker's 409) for the next send to this group, then behave normally. */
    public function busyOnceFor(string $groupName): static
    {
        $this->busy[$groupName] = true;

        return $this;
    }

    /** @var array<string, bool> */
    private array $busy = [];

    public function connect(): WhatsAppConnectionStatus
    {
        Cache::forever(self::STATE_KEY, true);

        return WhatsAppConnectionStatus::Connected;
    }

    public function disconnect(): void
    {
        Cache::forever(self::STATE_KEY, false);
    }

    public function status(): WhatsAppConnectionStatus
    {
        return $this->connected() ? WhatsAppConnectionStatus::Connected : WhatsAppConnectionStatus::Disconnected;
    }

    public function sendToGroup(Group $group, string $message, Media|iterable|null $attachment = null): SendResult
    {
        if ($delay = (int) config('educationhub.whatsapp.fake.delay_ms', 0)) {
            usleep($delay * 1000);
        }

        if (! $this->connected()) {
            return SendResult::failed($group->name, SendErrorType::WhatsAppDisconnected);
        }

        if (! empty($this->busy[$group->name])) {
            unset($this->busy[$group->name]);

            return SendResult::busy($group->name);
        }

        if ($type = $this->failures[$group->name] ?? null) {
            return SendResult::failed($group->name, $type, technical: "[fake] simulated {$type->value}");
        }

        $files = $this->fileList($attachment);

        $this->sent[] = [
            'group' => $group->name,
            'message' => $message,
            'attachment' => $files->first()?->original_name,
            'attachments' => $files->pluck('original_name')->all(),
        ];

        return SendResult::sent($group->name);
    }

    /** @return Collection<int, Media> */
    private function fileList(Media|iterable|null $attachment)
    {
        return $attachment === null
            ? collect()
            : collect($attachment instanceof Media ? [$attachment] : $attachment)->values();
    }

    private function connected(): bool
    {
        return (bool) Cache::get(self::STATE_KEY, true);
    }
}

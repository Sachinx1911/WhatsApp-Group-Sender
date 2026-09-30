<?php

namespace App\Services\WhatsApp;

use App\Enums\SendErrorType;
use App\Enums\WhatsAppConnectionStatus;
use App\Models\Group;
use App\Models\Media;

/**
 * Pretends to send. Nothing leaves this computer. Used by tests and while the
 * Playwright worker does not exist yet (WHATSAPP_DRIVER=fake).
 *
 * Failures can be simulated per group name through config
 * (educationhub.whatsapp.fake.failures, e.g. ['Police Batch 04' => 'NOT_MEMBER']),
 * or in tests with failFor() / disconnect().
 */
class FakeWhatsAppService implements WhatsAppServiceInterface
{
    /** @var array<string, SendErrorType> group name => error */
    private array $failures = [];

    private bool $connected = true;

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

    public function disconnect(): static
    {
        $this->connected = false;

        return $this;
    }

    public function status(): WhatsAppConnectionStatus
    {
        return $this->connected ? WhatsAppConnectionStatus::Connected : WhatsAppConnectionStatus::Disconnected;
    }

    public function sendToGroup(Group $group, string $message, ?Media $attachment = null): SendResult
    {
        if ($delay = (int) config('educationhub.whatsapp.fake.delay_ms', 0)) {
            usleep($delay * 1000);
        }

        if (! $this->connected) {
            return SendResult::failed($group->name, SendErrorType::WhatsAppDisconnected);
        }

        if ($type = $this->failures[$group->name] ?? null) {
            return SendResult::failed($group->name, $type, technical: "[fake] simulated {$type->value}");
        }

        $this->sent[] = ['group' => $group->name, 'message' => $message, 'attachment' => $attachment?->original_name];

        return SendResult::sent($group->name);
    }
}

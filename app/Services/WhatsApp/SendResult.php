<?php

namespace App\Services\WhatsApp;

use App\Enums\SendErrorType;
use Carbon\CarbonImmutable;

/**
 * Outcome of sending to one group (docs/MASTER_PROMPT.md §32):
 * { success, group, timestamp } or { success: false, group, error_type, error_message }.
 */
final class SendResult
{
    private function __construct(
        public readonly bool $success,
        public readonly string $group,
        public readonly ?SendErrorType $errorType = null,
        public readonly ?string $errorMessage = null,
        public readonly ?string $technicalDetails = null,
        public readonly ?CarbonImmutable $timestamp = null,
        /** The worker refused because its browser is busy with another task; nothing was attempted. */
        public readonly bool $workerBusy = false,
    ) {}

    public static function busy(string $group): self
    {
        return new self(false, $group, SendErrorType::Unknown, 'The sender is busy with another task.', 'Worker busy with another send.', CarbonImmutable::now(), workerBusy: true);
    }

    public static function sent(string $group): self
    {
        return new self(true, $group, timestamp: CarbonImmutable::now());
    }

    public static function failed(string $group, SendErrorType $type, ?string $message = null, ?string $technical = null): self
    {
        return new self(false, $group, $type, $message ?? $type->label(), $technical, CarbonImmutable::now());
    }

    /** Build from the worker's JSON response. Unknown error codes become UNKNOWN_ERROR. */
    public static function fromArray(array $data): self
    {
        $group = (string) ($data['group'] ?? '');

        if (($data['success'] ?? false) === true) {
            return self::sent($group);
        }

        return self::failed(
            $group,
            SendErrorType::tryFrom((string) ($data['error_type'] ?? '')) ?? SendErrorType::Unknown,
            isset($data['error_message']) ? (string) $data['error_message'] : null,
            isset($data['technical']) ? (string) $data['technical'] : null,
        );
    }

    public function toArray(): array
    {
        return $this->success
            ? ['success' => true, 'group' => $this->group, 'timestamp' => $this->timestamp?->toIso8601String()]
            : ['success' => false, 'group' => $this->group, 'error_type' => $this->errorType->value, 'error_message' => $this->errorMessage];
    }
}

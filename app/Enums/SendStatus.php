<?php

namespace App\Enums;

enum SendStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Sent => 'Sent',
            self::Failed => 'Failed',
            self::Skipped => 'Skipped',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Sent => 'success',
            self::Processing => 'primary',
            self::Pending => 'warning',
            self::Failed => 'danger',
            self::Skipped, self::Cancelled => 'muted',
        };
    }

    /** No further send attempt will be made for this row. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Sent, self::Skipped, self::Cancelled], true);
    }
}

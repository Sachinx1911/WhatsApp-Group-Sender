<?php

namespace App\Enums;

enum CampaignStatus: string
{
    case Draft = 'draft';
    case Queued = 'queued';
    case Sending = 'sending';
    case Paused = 'paused';
    case Completed = 'completed';
    case PartiallyFailed = 'partially_failed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Queued => 'Queued',
            self::Sending => 'Sending',
            self::Paused => 'Paused',
            self::Completed => 'Completed',
            self::PartiallyFailed => 'Partially Failed',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Design-token color used by the status badge. */
    public function color(): string
    {
        return match ($this) {
            self::Completed => 'success',
            self::Sending, self::Queued => 'primary',
            self::Paused, self::PartiallyFailed => 'warning',
            self::Failed => 'danger',
            self::Draft, self::Cancelled => 'muted',
        };
    }

    /** Campaign is still occupying (or waiting for) the single sender. */
    public function isActive(): bool
    {
        return in_array($this, [self::Queued, self::Sending, self::Paused], true);
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::PartiallyFailed, self::Failed, self::Cancelled], true);
    }
}

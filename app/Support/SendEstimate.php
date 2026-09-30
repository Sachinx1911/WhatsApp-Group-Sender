<?php

namespace App\Support;

/**
 * Rough sending time: groups × (configured delay + average send time)
 * (docs/MASTER_PROMPT.md §10). Sending is strictly one group at a time.
 */
class SendEstimate
{
    public static function secondsPerGroup(): int
    {
        return (int) config('educationhub.sending.delay_seconds', 15)
            + (int) config('educationhub.sending.average_send_seconds', 8);
    }

    public static function seconds(int $groups): int
    {
        return max(0, $groups) * self::secondsPerGroup();
    }

    /** "≈ 1 h 32 min", "≈ 4 min", "< 1 min". */
    public static function label(int $groups): string
    {
        $seconds = self::seconds($groups);

        if ($seconds < 60) {
            return '< 1 min';
        }

        $minutes = (int) ceil($seconds / 60);
        $hours = intdiv($minutes, 60);
        $minutes %= 60;

        return '≈ '.trim(($hours ? "{$hours} h " : '').($minutes ? "{$minutes} min" : ''));
    }
}

<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

/** Disk space used by uploaded media, shown in the sidebar Storage Usage card. */
class StorageUsage
{
    public const CACHE_KEY = 'storage-usage.media-bytes';

    /** @return array{used: int, quota: int, percent: float, used_label: string, quota_label: string} */
    public static function summary(): array
    {
        $used = Cache::remember(self::CACHE_KEY, now()->addMinutes(5), fn () => self::measure());
        $quota = (int) config('educationhub.storage_quota_gb', 10) * 1024 ** 3;

        return [
            'used' => $used,
            'quota' => $quota,
            'percent' => $quota > 0 ? min(100, round($used / $quota * 100, 1)) : 0,
            'used_label' => Number::fileSize($used, precision: 1),
            'quota_label' => Number::fileSize($quota),
        ];
    }

    /** Call after uploading or deleting media so the card updates immediately. */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private static function measure(): int
    {
        $disk = Storage::disk('local');

        return collect($disk->allFiles('media'))->sum(fn (string $file) => $disk->size($file));
    }
}

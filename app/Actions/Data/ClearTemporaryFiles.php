<?php

namespace App\Actions\Data;

use Illuminate\Support\Facades\Storage;

/**
 * Removes unfinished uploads (Livewire temporary files) older than the retention
 * set in Settings → Media. Uploaded media and backups are never touched.
 */
class ClearTemporaryFiles
{
    public const DIRECTORY = 'livewire-tmp';

    /** @return array{files: int, bytes: int} */
    public function handle(?int $olderThanDays = null): array
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subDays($olderThanDays ?? (int) config('educationhub.media.temp_retention_days', 1))->getTimestamp();
        $removed = ['files' => 0, 'bytes' => 0];

        foreach ($disk->allFiles(self::DIRECTORY) as $file) {
            if ($disk->lastModified($file) <= $cutoff) {
                $removed['bytes'] += $disk->size($file);
                $removed['files']++;
                $disk->delete($file);
            }
        }

        return $removed;
    }
}

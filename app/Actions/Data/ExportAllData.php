<?php

namespace App\Actions\Data;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * "Export All Data" (docs/MASTER_PROMPT.md §28): one ZIP with every table as JSON
 * (data.json) and all uploaded media files. Passwords, login sessions and the
 * WhatsApp browser session are never included.
 */
class ExportAllData
{
    public const DIRECTORY = 'backups';

    /** Tables included, in an order that can be restored (parents first). */
    public const TABLES = [
        'categories', 'media', 'groups', 'message_templates', 'campaigns',
        'campaign_groups', 'send_logs', 'settings', 'app_notifications',
    ];

    /** @return string path of the ZIP on the private "local" disk */
    public function handle(string $reason = 'export'): string
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory(self::DIRECTORY);

        $name = sprintf('education-hub-%s-%s.zip', $reason, now()->format('Y-m-d-His'));
        $path = self::DIRECTORY.'/'.$name;

        $zip = new ZipArchive;
        if ($zip->open($disk->path($path), ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the backup file.');
        }

        $data = [
            'app' => 'Education Hub — WhatsApp Group Sender',
            'exported_at' => now()->toIso8601String(),
            'tables' => collect(self::TABLES)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()])->all(),
        ];
        $zip->addFromString('data.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        foreach ($disk->allFiles('media') as $file) {
            $zip->addFile($disk->path($file), $file);
        }

        $zip->close();

        return $path;
    }

    /** Backups on disk, newest first. @return array<int, array{name: string, size: int, created: int}> */
    public static function list(): array
    {
        $disk = Storage::disk('local');

        return collect($disk->files(self::DIRECTORY))
            ->filter(fn ($f) => str_ends_with($f, '.zip'))
            ->map(fn ($f) => ['name' => basename($f), 'size' => $disk->size($f), 'created' => $disk->lastModified($f)])
            ->sortByDesc('created')
            ->values()
            ->all();
    }

    /** Only plain backup file names are accepted (no paths). */
    public static function isValidName(string $name): bool
    {
        // The reason may contain hyphens ("before-reset"), so allow hyphenated words.
        return (bool) preg_match('/^education-hub-[a-z]+(?:-[a-z]+)*-\d{4}-\d{2}-\d{2}-\d{6}\.zip$/', $name);
    }
}

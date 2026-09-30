<?php

namespace App\Actions\Data;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Support\Settings;
use App\Support\StorageUsage;
use Database\Seeders\CategorySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * "Reset Application" (docs/MASTER_PROMPT.md §28): removes all groups, templates,
 * media, campaigns, history, notifications and settings, and restores the default
 * categories. The admin account and the WhatsApp connection are kept. An automatic
 * export is always created first.
 */
class ResetApplication
{
    public function __construct(private ExportAllData $export) {}

    /** @return string path of the automatic backup made before resetting */
    public function handle(): string
    {
        if (Campaign::whereIn('status', [CampaignStatus::Queued, CampaignStatus::Sending, CampaignStatus::Paused])->exists()) {
            throw new RuntimeException('A campaign is still sending or waiting. Cancel it first, then reset.');
        }

        $backup = $this->export->handle('before-reset');

        DB::transaction(function () {
            foreach (['send_logs', 'campaign_groups', 'campaigns', 'message_templates', 'groups', 'media', 'app_notifications', 'settings', 'categories'] as $table) {
                DB::table($table)->delete();
            }
            DB::table('jobs')->where('queue', 'whatsapp')->delete();
        });

        Storage::disk('local')->deleteDirectory('media');
        app(CategorySeeder::class)->run();
        Settings::reset();
        StorageUsage::forget();

        Log::channel('whatsapp')->warning('Application reset', ['backup' => $backup]);

        return $backup;
    }
}

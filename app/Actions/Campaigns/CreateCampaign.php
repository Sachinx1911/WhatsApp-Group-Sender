<?php

namespace App\Actions\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\SendStatus;
use App\Models\Campaign;
use App\Models\CampaignGroup;
use App\Models\Group;
use App\Models\Media;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\Campaigns\CampaignRunner;
use App\Support\WhatsAppFormatter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creates a campaign with one pending row per active group, then lets the runner
 * start it (or keep it queued behind the campaign that is sending now).
 */
class CreateCampaign
{
    public function __construct(private CampaignRunner $runner) {}

    /**
     * @param  array<int, int|string>  $groupIds
     */
    public function handle(
        string $message,
        ?Media $attachment,
        array $groupIds,
        bool $isTest = false,
        ?MessageTemplate $template = null,
        ?User $user = null,
    ): Campaign {
        $groups = Group::active()->whereKey(array_map('intval', $groupIds))->orderBy('name')->get();

        if ($groups->isEmpty()) {
            throw new InvalidArgumentException('Select at least one active group.');
        }

        $campaign = DB::transaction(function () use ($message, $attachment, $groups, $isTest, $template, $user) {
            $campaign = Campaign::create([
                'title' => $this->title($message, $attachment, $template, $isTest),
                'message' => $message,
                'attachment_id' => $attachment?->id,
                'attachment_name' => $attachment?->original_name,
                'is_test' => $isTest,
                'total_groups' => $groups->count(),
                'pending_count' => $groups->count(),
                'status' => CampaignStatus::Queued,
                'created_by' => $user?->id,
            ]);

            $now = now();
            CampaignGroup::insert($groups->map(fn (Group $group) => [
                'campaign_id' => $campaign->id,
                'group_id' => $group->id,
                'group_name' => $group->name,
                'status' => SendStatus::Pending->value,
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());

            if (! $isTest) {
                $template?->update(['usage_count' => $template->usage_count + 1, 'last_used_at' => $now]);
                $attachment?->increment('usage_count');
            }

            return $campaign;
        });

        Log::channel('whatsapp')->info('Campaign created', ['campaign' => $campaign->id, 'groups' => $groups->count(), 'test' => $isTest]);

        $this->runner->startNextIfIdle();

        return $campaign->refresh();
    }

    /** "Daily MCQ Practice — 30 Sep", or the first line of the message when no template was used. */
    private function title(string $message, ?Media $attachment, ?MessageTemplate $template, bool $isTest): string
    {
        $firstLine = WhatsAppFormatter::plain(Str::before(trim($message), "\n"));
        $base = $template?->title ?: ($firstLine !== '' ? $firstLine : ($attachment?->original_name ?? 'Message'));

        return Str::limit(($isTest ? 'Test — ' : '').$base, 90).' — '.now()->format('d M');
    }
}

<?php

namespace Database\Seeders;

use App\Enums\CampaignStatus;
use App\Enums\SendErrorType;
use App\Enums\SendStatus;
use App\Models\AppNotification;
use App\Models\Campaign;
use App\Models\CampaignGroup;
use App\Models\Group;
use App\Models\Media;
use App\Models\MessageTemplate;
use App\Models\SendLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Two weeks of sending history with consistent counters, per-group results,
 * send logs and notifications. Skipped when campaigns already exist, so it never
 * mixes demo history into real data.
 */
class DemoCampaignSeeder extends Seeder
{
    private const TITLES = [
        'current_affairs' => 'Current Affairs',
        'daily_mcq' => 'MPSC Daily MCQ',
        'police_paper' => 'Police Bharti Practice Paper',
        'free_notes' => 'Free Batch Weekly Notes',
        'premium_live' => 'Premium Live Class',
        'holiday_notice' => 'Holiday Notice',
    ];

    /** Problems that affect a group in every campaign from N days ago onwards. */
    private const PERSISTENT_ISSUES = [
        'Police Batch 04' => [SendErrorType::NotMember, 8],
        'MPSC Batch 07' => [SendErrorType::OnlyAdminsCanSend, 4],
    ];

    /** Seconds between two groups, matching a conservative configured delay. */
    private const SECONDS_PER_GROUP = 18;

    private Collection $groups;

    private Collection $templates;

    private Collection $media;

    private array $templateUsage = [];

    private array $mediaUsage = [];

    public function run(): void
    {
        if (Campaign::exists()) {
            $this->command?->warn('Campaigns already exist; demo history skipped.');

            return;
        }

        $this->groups = Group::with('category')->active()->orderBy('name')->get();
        $this->templates = MessageTemplate::all()->keyBy('title');
        $this->media = Media::all()->keyBy('original_name');
        $adminId = User::value('id');

        foreach ($this->plan() as $plan) {
            $this->seedCampaign($plan, $adminId);
        }

        $this->syncUsageCounters();
        $this->syncGroupLastSent();
    }

    /** @return array<int, array<string, mixed>> */
    private function plan(): array
    {
        $day = fn (int $daysAgo, string $time) => CarbonImmutable::now()->subDays($daysAgo)->setTimeFromTimeString($time);
        $today = fn (int $minutesAgo) => CarbonImmutable::now()->subMinutes($minutesAgo);
        $olderPdf = 'Current_Affairs_29_09_2026.pdf';

        return [
            ['template' => 'daily_mcq', 'targets' => ['MPSC'], 'at' => $day(9, '08:00'), 'days_ago' => 9],
            ['template' => 'police_paper', 'targets' => ['Police Bharti'], 'at' => $day(8, '18:00'), 'days_ago' => 8],
            ['template' => 'current_affairs', 'attachment' => $olderPdf, 'targets' => ['MPSC', 'Combined'], 'at' => $day(7, '07:30'), 'days_ago' => 7],
            ['template' => 'free_notes', 'targets' => ['Free'], 'at' => $day(6, '17:00'), 'days_ago' => 6],
            ['template' => 'premium_live', 'targets' => ['Premium'], 'at' => $day(5, '16:00'), 'days_ago' => 5],
            ['template' => 'holiday_notice', 'targets' => 'all', 'at' => $day(4, '12:00'), 'days_ago' => 4,
                'transient' => ['Combined Batch 05' => SendErrorType::GroupNotFound], 'skipped' => ['MPSC Batch 07']],
            ['template' => 'current_affairs', 'attachment' => $olderPdf, 'targets' => ['MPSC', 'Combined'], 'at' => $day(3, '07:30'), 'days_ago' => 3,
                'cancel_after' => 8],
            ['template' => 'daily_mcq', 'targets' => ['MPSC'], 'at' => $day(2, '08:00'), 'days_ago' => 2,
                'all_fail' => SendErrorType::MediaUploadFailed],
            ['template' => 'daily_mcq', 'targets' => ['MPSC'], 'at' => $day(2, '09:00'), 'days_ago' => 2, 'suffix' => ' (resend)'],
            ['template' => 'current_affairs', 'attachment' => $olderPdf, 'targets' => ['MPSC', 'Combined'], 'at' => $day(1, '07:30'), 'days_ago' => 1,
                'transient' => ['Combined Batch 03' => SendErrorType::Timeout]],
            ['template' => 'current_affairs', 'targets' => 'test', 'at' => $today(200), 'days_ago' => 0, 'is_test' => true],
            ['template' => 'current_affairs', 'targets' => ['MPSC', 'Combined', 'Police Bharti'], 'at' => $today(180), 'days_ago' => 0,
                'transient' => ['Police Batch 02' => SendErrorType::Timeout]],
            ['template' => 'premium_live', 'targets' => ['Premium'], 'at' => $today(90), 'days_ago' => 0],
            ['template' => 'police_paper', 'targets' => ['Police Bharti'], 'at' => $today(45), 'days_ago' => 0],
        ];
    }

    private function seedCampaign(array $plan, ?int $adminId): void
    {
        $template = $this->templates[DemoTemplateSeeder::TEMPLATES[$plan['template']]['title']];
        $attachmentName = $plan['attachment'] ?? DemoTemplateSeeder::TEMPLATES[$plan['template']]['attachment'];
        $attachment = $attachmentName ? $this->media[$attachmentName] : null;
        /** @var CarbonImmutable $startedAt */
        $startedAt = $plan['at'];
        $isTest = $plan['is_test'] ?? false;

        $title = ($isTest ? 'Test — ' : '').self::TITLES[$plan['template']].' — '.$startedAt->format('d M').($plan['suffix'] ?? '');

        $targets = match (true) {
            $plan['targets'] === 'test' => Group::where('name', DemoGroupSeeder::TEST_GROUP)->get(),
            $plan['targets'] === 'all' => $this->groups->where('name', '!=', DemoGroupSeeder::TEST_GROUP),
            default => $this->groups->filter(fn (Group $g) => in_array($g->category->name, $plan['targets'], true)),
        };

        $campaign = Campaign::create([
            'title' => $title,
            'message' => $template->message,
            'attachment_id' => $attachment?->id,
            'attachment_name' => $attachment?->original_name,
            'is_test' => $isTest,
            'created_by' => $adminId,
            'status' => CampaignStatus::Draft,
        ]);

        $rows = [];
        $logs = [];
        $counts = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        $time = $startedAt;

        foreach ($targets->values() as $index => $group) {
            $time = $time->addSeconds(self::SECONDS_PER_GROUP + mt_rand(0, 6));
            [$status, $error] = $this->outcome($plan, $index, $group->name);

            $row = [
                'campaign_id' => $campaign->id,
                'group_id' => $group->id,
                'group_name' => $group->name,
                'status' => $status->value,
                'attempts' => match (true) {
                    $status === SendStatus::Cancelled => 0,
                    $error?->isRetryable() => 3,
                    default => 1,
                },
                'error_type' => $error?->value,
                'error_message' => $error?->label(),
                'sent_at' => $status === SendStatus::Sent ? $time : null,
                'created_at' => $startedAt,
                'updated_at' => $time,
            ];
            $rows[] = $row;

            match ($status) {
                SendStatus::Sent => $counts['sent']++,
                SendStatus::Failed => $counts['failed']++,
                SendStatus::Skipped => $counts['skipped']++,
                default => null,
            };

            if ($status !== SendStatus::Cancelled) {
                $logs[] = [
                    'campaign_id' => $campaign->id,
                    'group_id' => $group->id,
                    'group_name' => $group->name,
                    'status' => $status === SendStatus::Skipped ? SendStatus::Failed->value : $status->value,
                    'message' => $status === SendStatus::Sent ? 'Message sent successfully' : "Unable to send to {$group->name}",
                    'error_type' => $error?->value,
                    'error_message' => $error?->description(),
                    'technical_details' => $error ? "[demo data] {$error->value} while sending to \"{$group->name}\"" : null,
                    'created_at' => $time,
                    'updated_at' => $time,
                ];
            }
        }

        CampaignGroup::insert($rows);
        SendLog::insert($logs);

        $status = match (true) {
            isset($plan['cancel_after']) => CampaignStatus::Cancelled,
            $counts['sent'] === 0 && $counts['failed'] > 0 => CampaignStatus::Failed,
            $counts['failed'] > 0 => CampaignStatus::PartiallyFailed,
            default => CampaignStatus::Completed,
        };

        $campaign->forceFill([
            'total_groups' => count($rows),
            'sent_count' => $counts['sent'],
            'failed_count' => $counts['failed'],
            'skipped_count' => $counts['skipped'],
            'pending_count' => 0,
            'status' => $status,
            'started_at' => $startedAt,
            'completed_at' => $time->addSeconds(3),
            'created_at' => $startedAt->subMinute(),
            'updated_at' => $time->addSeconds(3),
        ])->saveQuietly();

        if (! $isTest) {
            $this->templateUsage[$template->id][] = $startedAt;
        }
        if ($attachment) {
            $this->mediaUsage[$attachment->id] = ($this->mediaUsage[$attachment->id] ?? 0) + 1;
        }

        $this->notify($campaign, $plan['days_ago']);
    }

    /** @return array{0: SendStatus, 1: ?SendErrorType} */
    private function outcome(array $plan, int $index, string $groupName): array
    {
        if (isset($plan['cancel_after']) && $index >= $plan['cancel_after']) {
            return [SendStatus::Cancelled, null];
        }

        $error = $plan['all_fail'] ?? $plan['transient'][$groupName] ?? null;

        if (! $error && isset(self::PERSISTENT_ISSUES[$groupName])) {
            [$issue, $sinceDaysAgo] = self::PERSISTENT_ISSUES[$groupName];
            $error = $plan['days_ago'] <= $sinceDaysAgo ? $issue : null;
        }

        if (! $error) {
            return [SendStatus::Sent, null];
        }

        return in_array($groupName, $plan['skipped'] ?? [], true)
            ? [SendStatus::Skipped, $error]
            : [SendStatus::Failed, $error];
    }

    private function notify(Campaign $campaign, int $daysAgo): void
    {
        if ($campaign->is_test || $daysAgo > 3) {
            return;
        }

        [$type, $title, $body] = match ($campaign->status) {
            CampaignStatus::Completed => ['campaign_completed', 'Campaign completed', "\"{$campaign->title}\" was sent to {$campaign->sent_count} groups."],
            CampaignStatus::PartiallyFailed => ['campaign_failures', 'Some messages failed', "{$campaign->failed_count} of {$campaign->total_groups} groups failed in \"{$campaign->title}\"."],
            CampaignStatus::Failed => ['campaign_failures', 'Campaign failed', "No group received \"{$campaign->title}\"."],
            CampaignStatus::Cancelled => ['campaign_cancelled', 'Campaign cancelled', "\"{$campaign->title}\" was cancelled after {$campaign->sent_count} groups."],
            default => [null, null, null],
        };

        if (! $type) {
            return;
        }

        $at = $campaign->completed_at->toImmutable();

        AppNotification::create(['type' => $type, 'title' => $title, 'body' => $body])
            ->forceFill(['created_at' => $at, 'updated_at' => $at, 'read_at' => $daysAgo > 0 ? $at->addHour() : null])
            ->saveQuietly();
    }

    private function syncUsageCounters(): void
    {
        foreach ($this->templateUsage as $templateId => $dates) {
            MessageTemplate::whereKey($templateId)->update([
                'usage_count' => count($dates),
                'last_used_at' => max($dates),
            ]);
        }

        foreach ($this->mediaUsage as $mediaId => $count) {
            Media::whereKey($mediaId)->update(['usage_count' => $count]);
        }
    }

    private function syncGroupLastSent(): void
    {
        CampaignGroup::query()
            ->where('status', SendStatus::Sent)
            ->selectRaw('group_id, max(sent_at) as last_sent_at')
            ->groupBy('group_id')
            ->get()
            ->each(fn ($row) => Group::whereKey($row->group_id)->update(['last_sent_at' => $row->last_sent_at]));
    }
}

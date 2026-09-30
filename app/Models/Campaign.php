<?php

namespace App\Models;

use App\Enums\CampaignStatus;
use App\Enums\SendStatus;
use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'title', 'message', 'attachment_id', 'attachment_name', 'is_test',
    'total_groups', 'sent_count', 'failed_count', 'pending_count', 'skipped_count',
    'status', 'created_by', 'started_at', 'paused_at', 'completed_at',
])]
class Campaign extends Model
{
    /** @use HasFactory<CampaignFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'is_test' => 'boolean',
            'total_groups' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
            'pending_count' => 'integer',
            'skipped_count' => 'integer',
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'attachment_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function campaignGroups(): HasMany
    {
        return $this->hasMany(CampaignGroup::class);
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'campaign_groups')
            ->using(CampaignGroup::class)
            ->withPivot(['id', 'status', 'attempts', 'error_type', 'error_message', 'sent_at'])
            ->withTimestamps();
    }

    public function sendLogs(): HasMany
    {
        return $this->hasMany(SendLog::class);
    }

    #[Scope]
    protected function excludingTests(Builder $query): void
    {
        $query->where('is_test', false);
    }

    /**
     * Send History filters (docs/MASTER_PROMPT.md §17).
     * status "in_progress" means queued, sending or paused.
     *
     * @param  array{search?: ?string, category?: int|string|null, status?: ?string, from?: ?string, to?: ?string, hide_tests?: bool}  $filters
     */
    #[Scope]
    protected function filter(Builder $query, array $filters): void
    {
        $term = trim((string) ($filters['search'] ?? ''));
        $status = (string) ($filters['status'] ?? '');

        $query
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('title', 'like', "%{$term}%")
                ->orWhere('message', 'like', "%{$term}%")
                ->orWhereHas('campaignGroups', fn (Builder $g) => $g->where('group_name', 'like', "%{$term}%"))))
            ->when(filled($filters['category'] ?? null), fn (Builder $q) => $q->whereHas(
                'campaignGroups.group', fn (Builder $g) => $g->where('category_id', (int) $filters['category'])))
            ->when($status === 'in_progress', fn (Builder $q) => $q->whereIn('status', [CampaignStatus::Queued, CampaignStatus::Sending, CampaignStatus::Paused]))
            ->when($status !== 'in_progress' && CampaignStatus::tryFrom($status), fn (Builder $q) => $q->where('status', $status))
            ->when(filled($filters['from'] ?? null), fn (Builder $q) => $q->whereDate('created_at', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn (Builder $q) => $q->whereDate('created_at', '<=', $filters['to']))
            ->when($filters['hide_tests'] ?? false, fn (Builder $q) => $q->where('is_test', false));
    }

    /** Recount sent / failed / skipped / pending from the per-group rows (always consistent). */
    public function refreshCounters(): static
    {
        $counts = $this->campaignGroups()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $this->forceFill([
            'sent_count' => (int) ($counts[SendStatus::Sent->value] ?? 0),
            'failed_count' => (int) ($counts[SendStatus::Failed->value] ?? 0),
            'skipped_count' => (int) ($counts[SendStatus::Skipped->value] ?? 0),
            'pending_count' => (int) ($counts[SendStatus::Pending->value] ?? 0) + (int) ($counts[SendStatus::Processing->value] ?? 0),
        ])->save();

        return $this;
    }

    /** Outcome once no group is pending: completed, partially failed or failed. */
    public function outcomeStatus(): CampaignStatus
    {
        return match (true) {
            $this->failed_count > 0 && $this->sent_count === 0 => CampaignStatus::Failed,
            $this->failed_count > 0 => CampaignStatus::PartiallyFailed,
            default => CampaignStatus::Completed,
        };
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /** Groups that have reached a final outcome (sent, failed, skipped). */
    public function processedCount(): int
    {
        return $this->sent_count + $this->failed_count + $this->skipped_count;
    }

    public function progressPercent(): float
    {
        return $this->total_groups > 0
            ? round($this->processedCount() / $this->total_groups * 100, 1)
            : 0.0;
    }
}

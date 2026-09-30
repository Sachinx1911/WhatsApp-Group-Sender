<?php

namespace App\Models;

use App\Enums\CampaignStatus;
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

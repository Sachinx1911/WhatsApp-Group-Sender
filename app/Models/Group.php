<?php

namespace App\Models;

use App\Enums\GroupStatus;
use App\Enums\SendStatus;
use Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'category_id', 'member_count', 'whatsapp_identifier', 'status', 'last_sent_at'])]
class Group extends Model
{
    /** @use HasFactory<GroupFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => GroupStatus::class,
            'member_count' => 'integer',
            'last_sent_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function campaignGroups(): HasMany
    {
        return $this->hasMany(CampaignGroup::class);
    }

    public function sendLogs(): HasMany
    {
        return $this->hasMany(SendLog::class);
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', GroupStatus::Active);
    }

    /** Matches group name or category name. */
    #[Scope]
    protected function search(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        // Settings → Group Settings → Group search: "contains" (default) or "starts with".
        $pattern = config('educationhub.groups.search_mode') === 'starts_with' ? "{$term}%" : "%{$term}%";

        $query->where(function (Builder $query) use ($pattern) {
            $query->where('name', 'like', $pattern)
                ->orWhereHas('category', fn (Builder $q) => $q->where('name', 'like', $pattern));
        });
    }

    /**
     * Group Manager / export / group selector filters.
     *
     * @param  array{search?: ?string, category?: int|string|null, status?: ?string, min_members?: int|string|null, max_members?: int|string|null}  $filters
     */
    #[Scope]
    protected function filter(Builder $query, array $filters): void
    {
        $query
            ->search($filters['search'] ?? null)
            ->when(filled($filters['category'] ?? null), fn (Builder $q) => $q->where('category_id', (int) $filters['category']))
            ->when(GroupStatus::tryFrom((string) ($filters['status'] ?? '')), fn (Builder $q, GroupStatus $s) => $q->where('status', $s))
            ->when(is_numeric($filters['min_members'] ?? null), fn (Builder $q) => $q->where('member_count', '>=', (int) $filters['min_members']))
            ->when(is_numeric($filters['max_members'] ?? null), fn (Builder $q) => $q->where('member_count', '<=', (int) $filters['max_members']));
    }

    /** Group used by "Send Test": the configured one, else the group named "Education Hub Test Group". */
    public static function testGroup(): ?self
    {
        $id = config('educationhub.sending.test_group_id');

        return ($id ? static::find($id) : null) ?? static::firstWhere('name', 'Education Hub Test Group');
    }

    public function isActive(): bool
    {
        return $this->status === GroupStatus::Active;
    }

    /** Part of a campaign that has not finished sending to it yet. */
    public function hasPendingSends(): bool
    {
        return $this->campaignGroups()->whereIn('status', [SendStatus::Pending, SendStatus::Processing])->exists();
    }
}

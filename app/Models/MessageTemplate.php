<?php

namespace App\Models;

use App\Enums\MediaType;
use Database\Factories\MessageTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['title', 'category_id', 'message', 'attachment_id', 'tags', 'usage_count', 'last_used_at'])]
class MessageTemplate extends Model
{
    /** @use HasFactory<MessageTemplateFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'usage_count' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'attachment_id');
    }

    /**
     * Templates screen filters. Type is "text" (no attachment), "image" or "pdf".
     *
     * @param  array{search?: ?string, category?: int|string|null, type?: ?string}  $filters
     */
    #[Scope]
    protected function filter(Builder $query, array $filters): void
    {
        $term = trim((string) ($filters['search'] ?? ''));
        $type = $filters['type'] ?? '';

        $query
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('title', 'like', "%{$term}%")
                ->orWhere('message', 'like', "%{$term}%")
                ->orWhere('tags', 'like', '%'.ltrim($term, '#').'%')))
            ->when(filled($filters['category'] ?? null), fn (Builder $q) => $q->where('category_id', (int) $filters['category']))
            ->when($type === 'text', fn (Builder $q) => $q->whereNull('attachment_id'))
            ->when(MediaType::tryFrom((string) $type), fn (Builder $q, MediaType $t) => $q->whereHas('attachment', fn (Builder $a) => $a->where('type', $t)));
    }

    /** Text / Text + Image / Text + PDF */
    public function typeLabel(): string
    {
        return match ($this->attachment?->type?->value) {
            'image' => 'Text + Image',
            'pdf' => 'Text + PDF',
            default => 'Text',
        };
    }
}

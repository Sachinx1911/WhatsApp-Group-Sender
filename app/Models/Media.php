<?php

namespace App\Models;

use App\Enums\CampaignStatus;
use App\Enums\MediaType;
use App\Support\StorageUsage;
use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

#[Fillable(['filename', 'original_name', 'path', 'thumbnail_path', 'mime_type', 'size', 'type', 'usage_count'])]
class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory;

    protected $table = 'media';

    protected function casts(): array
    {
        return [
            'type' => MediaType::class,
            'size' => 'integer',
            'usage_count' => 'integer',
        ];
    }

    public function templates(): HasMany
    {
        return $this->hasMany(MessageTemplate::class, 'attachment_id');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class, 'attachment_id');
    }

    public function isImage(): bool
    {
        return $this->type === MediaType::Image;
    }

    public function humanSize(): string
    {
        return Number::fileSize($this->size, precision: 1);
    }

    /** A campaign that has not finished yet still needs this file. */
    public function isNeededByActiveCampaign(): bool
    {
        return $this->campaigns()
            ->whereIn('status', [CampaignStatus::Queued, CampaignStatus::Sending, CampaignStatus::Paused])
            ->exists();
    }

    /** Remove the record and its files from the private disk. */
    public function deleteWithFiles(): void
    {
        Storage::disk('local')->delete(array_filter([$this->path, $this->thumbnail_path]));
        $this->delete();

        StorageUsage::forget();
    }
}

<?php

namespace App\Models;

use App\Enums\SendErrorType;
use App\Enums\SendStatus;
use Database\Factories\CampaignGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * One group's delivery state within a campaign. Used both as a model and as the
 * pivot of Campaign::groups().
 */
#[Fillable(['campaign_id', 'group_id', 'group_name', 'status', 'attempts', 'error_type', 'error_message', 'sent_at'])]
class CampaignGroup extends Pivot
{
    /** @use HasFactory<CampaignGroupFactory> */
    use HasFactory;

    protected $table = 'campaign_groups';

    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'status' => SendStatus::class,
            'error_type' => SendErrorType::class,
            'attempts' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}

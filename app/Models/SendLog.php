<?php

namespace App\Models;

use App\Enums\SendErrorType;
use App\Enums\SendStatus;
use Database\Factories\SendLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['campaign_id', 'group_id', 'group_name', 'status', 'message', 'error_type', 'error_message', 'technical_details'])]
class SendLog extends Model
{
    /** @use HasFactory<SendLogFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => SendStatus::class,
            'error_type' => SendErrorType::class,
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

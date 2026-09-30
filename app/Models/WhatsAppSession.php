<?php

namespace App\Models;

use App\Enums\WhatsAppConnectionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['profile_name', 'status', 'last_connected_at', 'last_seen_at', 'metadata'])]
class WhatsAppSession extends Model
{
    protected $table = 'whatsapp_sessions';

    protected function casts(): array
    {
        return [
            'status' => WhatsAppConnectionStatus::class,
            'last_connected_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** The single local browser profile used for sending. */
    public static function current(): self
    {
        return static::firstOrCreate(
            ['profile_name' => 'default'],
            ['status' => WhatsAppConnectionStatus::Disconnected],
        );
    }

    public function isConnected(): bool
    {
        return $this->status === WhatsAppConnectionStatus::Connected;
    }
}

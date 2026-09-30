<?php

namespace App\Enums;

enum WhatsAppConnectionStatus: string
{
    case Starting = 'starting';
    case WaitingForQr = 'waiting_for_qr';
    case Connected = 'connected';
    case Disconnected = 'disconnected';

    public function label(): string
    {
        return match ($this) {
            self::Starting => 'Starting',
            self::WaitingForQr => 'Waiting for QR scan',
            self::Connected => 'Connected',
            self::Disconnected => 'Disconnected',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Connected => 'success',
            self::Starting, self::WaitingForQr => 'warning',
            self::Disconnected => 'danger',
        };
    }
}

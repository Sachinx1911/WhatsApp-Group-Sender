<?php

namespace App\Enums;

enum MediaType: string
{
    case Image = 'image';
    case Pdf = 'pdf';

    public function label(): string
    {
        return match ($this) {
            self::Image => 'Image',
            self::Pdf => 'PDF',
        };
    }

    public static function fromMime(string $mime): ?self
    {
        return match ($mime) {
            'image/jpeg', 'image/png' => self::Image,
            'application/pdf' => self::Pdf,
            default => null,
        };
    }
}

<?php

namespace App\Services\WhatsApp;

use RuntimeException;

/** The local WhatsApp Web worker is not running or did not answer. */
class WorkerUnavailableException extends RuntimeException
{
    public static function make(?string $details = null): self
    {
        return new self('The WhatsApp worker is not running. Start the app with start.bat and try again.'.($details ? " ({$details})" : ''));
    }
}

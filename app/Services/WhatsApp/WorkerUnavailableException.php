<?php

namespace App\Services\WhatsApp;

use RuntimeException;

/** The local WhatsApp Web worker is not running, did not answer, or could not do what was asked. */
class WorkerUnavailableException extends RuntimeException
{
    /** The worker could not be reached at all. */
    public static function make(?string $details = null): self
    {
        return new self('The WhatsApp worker is not running. Start the app with start.bat and try again.'.($details ? " ({$details})" : ''));
    }

    /**
     * The worker is running and answered, but WhatsApp Web would not do what was asked.
     * Telling the admin to restart the app here would send them down the wrong path.
     */
    public static function refused(?string $reason = null): self
    {
        return new self($reason ?: 'WhatsApp Web could not complete this action. Check the WhatsApp window and try again.');
    }
}

<?php

namespace App\Services\WhatsApp;

use App\Enums\WhatsAppConnectionStatus;
use App\Models\Group;
use App\Models\Media;

/**
 * The WhatsApp automation layer (docs/MASTER_PROMPT.md §3). The dashboard and the
 * queue only talk to this interface, so the implementation can be swapped:
 * FakeWhatsAppService (tests/development) or PlaywrightWhatsAppService (Phase 14).
 *
 * Connection methods throw WorkerUnavailableException when the local worker cannot be reached.
 */
interface WhatsAppServiceInterface
{
    public function status(): WhatsAppConnectionStatus;

    /**
     * Open WhatsApp Web in the visible browser window. Returns the state right after
     * starting: usually Starting or WaitingForQr (the admin scans the QR with their
     * phone), or Connected when the saved linked-device session is still valid.
     */
    public function connect(): WhatsAppConnectionStatus;

    /** Log out of WhatsApp Web (removes this linked device) and close the browser. */
    public function disconnect(): void;

    /**
     * Send one message (and optional attachment, with the text as caption) to a group.
     * Must not throw for expected problems: they are returned as a failed SendResult.
     */
    public function sendToGroup(Group $group, string $message, ?Media $attachment = null): SendResult;
}

<?php

namespace App\Services\WhatsApp;

use App\Enums\WhatsAppConnectionStatus;
use App\Models\Group;
use App\Models\Media;

/**
 * The WhatsApp automation layer (docs/MASTER_PROMPT.md §3). The dashboard and the
 * queue only talk to this interface, so the implementation can be swapped:
 * FakeWhatsAppService (tests/development) or PlaywrightWhatsAppService (Phase 14).
 */
interface WhatsAppServiceInterface
{
    public function status(): WhatsAppConnectionStatus;

    /**
     * Send one message (and optional attachment, with the text as caption) to a group.
     * Must not throw for expected problems: they are returned as a failed SendResult.
     */
    public function sendToGroup(Group $group, string $message, ?Media $attachment = null): SendResult;
}

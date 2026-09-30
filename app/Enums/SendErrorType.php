<?php

namespace App\Enums;

/**
 * Error categories returned by the WhatsApp layer (docs/MASTER_PROMPT.md §34).
 * Values match the error_type strings sent by the Playwright worker.
 */
enum SendErrorType: string
{
    case WhatsAppDisconnected = 'WHATSAPP_DISCONNECTED';
    case WorkerUnavailable = 'WORKER_UNAVAILABLE';
    case GroupNotFound = 'GROUP_NOT_FOUND';
    case NotMember = 'NOT_MEMBER';
    case OnlyAdminsCanSend = 'ONLY_ADMINS_CAN_SEND';
    case MediaUploadFailed = 'MEDIA_UPLOAD_FAILED';
    case MessageSendFailed = 'MESSAGE_SEND_FAILED';
    case BrowserError = 'BROWSER_ERROR';
    case Timeout = 'TIMEOUT';
    case DailyLimitReached = 'DAILY_LIMIT_REACHED';
    case Unconfirmed = 'UNCONFIRMED';
    case Unknown = 'UNKNOWN_ERROR';

    /** Short friendly text shown in tables. */
    public function label(): string
    {
        return match ($this) {
            self::WhatsAppDisconnected => 'WhatsApp disconnected',
            self::WorkerUnavailable => 'Sender not running',
            self::GroupNotFound => 'Group not found',
            self::NotMember => 'Not a member',
            self::OnlyAdminsCanSend => 'Only admins can send',
            self::MediaUploadFailed => 'Media upload failed',
            self::MessageSendFailed => 'Message not sent',
            self::BrowserError => 'Browser error',
            self::Timeout => 'Loading timeout',
            self::DailyLimitReached => 'Daily limit reached',
            self::Unconfirmed => 'Delivery unconfirmed',
            self::Unknown => 'Unknown error',
        };
    }

    /** Explanation and suggested fix shown in the failure detail drawer. */
    public function description(): string
    {
        return match ($this) {
            self::WhatsAppDisconnected => 'WhatsApp is not connected. Reconnect it from Settings → WhatsApp Connection, then resume the campaign.',
            self::WorkerUnavailable => 'The sending service is not running. Start the application with start.bat, then resume the campaign.',
            self::GroupNotFound => 'No WhatsApp group matches this name. Check that the group name matches WhatsApp exactly.',
            self::NotMember => 'You are not a member of this group, or the group no longer exists.',
            self::OnlyAdminsCanSend => 'Only admins can send messages in this group. Ask a group admin to make you an admin, or change the group setting.',
            self::MediaUploadFailed => 'The attachment could not be uploaded. Check the file and try again.',
            self::MessageSendFailed => 'WhatsApp did not confirm the message. Try sending again.',
            self::BrowserError => 'The browser hit an unexpected problem. Refresh the WhatsApp session and try again.',
            self::Timeout => 'WhatsApp took too long to respond. Check your internet connection and try again.',
            self::DailyLimitReached => 'The daily sending limit set in Settings was reached. Sending continues tomorrow, or you can raise the limit.',
            self::Unconfirmed => 'Sending was interrupted, so it is not known whether this group received the message. Check the group in WhatsApp, then Retry or Skip.',
            self::Unknown => 'An unknown error occurred. See the technical details for more information.',
        };
    }

    /** The job may be retried automatically (up to the attempt limit). */
    public function isRetryable(): bool
    {
        return in_array($this, [
            self::MediaUploadFailed,
            self::MessageSendFailed,
            self::BrowserError,
            self::Timeout,
            self::Unknown,
        ], true);
    }

    /** The whole campaign must pause instead of failing this group. */
    public function pausesCampaign(): bool
    {
        return in_array($this, [
            self::WhatsAppDisconnected,
            self::WorkerUnavailable,
            self::DailyLimitReached,
        ], true);
    }
}

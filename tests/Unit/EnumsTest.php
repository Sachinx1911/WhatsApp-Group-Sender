<?php

namespace Tests\Unit;

use App\Enums\CampaignStatus;
use App\Enums\MediaType;
use App\Enums\SendErrorType;
use App\Enums\SendStatus;
use PHPUnit\Framework\TestCase;

class EnumsTest extends TestCase
{
    public function test_error_types_match_worker_error_codes(): void
    {
        $this->assertSame([
            'WHATSAPP_DISCONNECTED', 'WORKER_UNAVAILABLE', 'GROUP_NOT_FOUND', 'NOT_MEMBER',
            'ONLY_ADMINS_CAN_SEND', 'MEDIA_UPLOAD_FAILED', 'MESSAGE_SEND_FAILED', 'BROWSER_ERROR',
            'TIMEOUT', 'DAILY_LIMIT_REACHED', 'UNCONFIRMED', 'UNKNOWN_ERROR',
        ], array_column(SendErrorType::cases(), 'value'));
    }

    public function test_every_error_type_has_friendly_text(): void
    {
        foreach (SendErrorType::cases() as $type) {
            $this->assertNotSame('', $type->label());
            $this->assertNotSame('', $type->description());
        }
    }

    public function test_connection_problems_pause_the_campaign_instead_of_retrying(): void
    {
        foreach ([SendErrorType::WhatsAppDisconnected, SendErrorType::WorkerUnavailable, SendErrorType::DailyLimitReached] as $type) {
            $this->assertTrue($type->pausesCampaign(), $type->value);
            $this->assertFalse($type->isRetryable(), $type->value);
        }
    }

    public function test_permanent_group_problems_are_not_retried(): void
    {
        foreach ([SendErrorType::GroupNotFound, SendErrorType::NotMember, SendErrorType::OnlyAdminsCanSend, SendErrorType::Unconfirmed] as $type) {
            $this->assertFalse($type->isRetryable(), $type->value);
            $this->assertFalse($type->pausesCampaign(), $type->value);
        }
    }

    public function test_unconfirmed_sends_are_never_retried_automatically(): void
    {
        // Retrying could deliver the same message twice (docs/MASTER_PROMPT.md §31).
        $this->assertFalse(SendErrorType::Unconfirmed->isRetryable());
    }

    public function test_campaign_and_send_status_groups(): void
    {
        $this->assertTrue(CampaignStatus::Paused->isActive());
        $this->assertTrue(CampaignStatus::PartiallyFailed->isFinished());
        $this->assertFalse(CampaignStatus::Sending->isFinished());
        $this->assertTrue(SendStatus::Skipped->isFinal());
        $this->assertFalse(SendStatus::Failed->isFinal());
    }

    public function test_media_type_from_mime(): void
    {
        $this->assertSame(MediaType::Image, MediaType::fromMime('image/jpeg'));
        $this->assertSame(MediaType::Pdf, MediaType::fromMime('application/pdf'));
        $this->assertNull(MediaType::fromMime('image/gif'));
    }
}

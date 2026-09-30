<?php

namespace Tests\Feature\Database;

use App\Enums\CampaignStatus;
use App\Enums\GroupStatus;
use App\Enums\SendErrorType;
use App\Enums\SendStatus;
use App\Enums\WhatsAppConnectionStatus;
use App\Models\Campaign;
use App\Models\CampaignGroup;
use App\Models\Category;
use App\Models\Group;
use App\Models\Media;
use App\Models\MessageTemplate;
use App\Models\Setting;
use App\Models\WhatsAppSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_group_search_matches_name_or_category(): void
    {
        $police = Category::factory()->create(['name' => 'Police Bharti']);
        $mpsc = Category::factory()->create(['name' => 'MPSC']);
        Group::factory()->for($police)->create(['name' => 'Police Batch 01']);
        Group::factory()->for($mpsc)->create(['name' => 'MPSC Batch 01']);
        Group::factory()->for($mpsc)->create(['name' => 'Evening Batch']);

        $this->assertEqualsCanonicalizing(['MPSC Batch 01', 'Evening Batch'], Group::search('mpsc')->pluck('name')->all());
        $this->assertSame(['Police Batch 01'], Group::search('Police Batch')->pluck('name')->all());
        $this->assertSame(3, Group::search('  ')->count());
    }

    public function test_active_scope_and_status_cast(): void
    {
        Group::factory()->create();
        $inactive = Group::factory()->inactive()->create();

        $this->assertSame(1, Group::active()->count());
        $this->assertSame(GroupStatus::Inactive, $inactive->fresh()->status);
        $this->assertFalse($inactive->isActive());
    }

    public function test_campaign_rows_cast_to_enums_and_link_groups(): void
    {
        $row = CampaignGroup::factory()->failed(SendErrorType::NotMember)->create();
        $campaign = $row->campaign;

        $this->assertSame(SendStatus::Failed, $row->fresh()->status);
        $this->assertSame(SendErrorType::NotMember, $row->fresh()->error_type);
        $this->assertTrue($campaign->groups->first()->is($row->group));
        $this->assertSame('failed', $campaign->groups->first()->pivot->status->value);
    }

    public function test_campaign_progress(): void
    {
        $campaign = Campaign::factory()->create([
            'status' => CampaignStatus::Sending,
            'total_groups' => 250, 'sent_count' => 142, 'failed_count' => 3, 'skipped_count' => 2, 'pending_count' => 103,
        ]);

        $this->assertSame(147, $campaign->processedCount());
        $this->assertSame(58.8, $campaign->progressPercent());
        $this->assertSame(0.0, Campaign::factory()->make(['total_groups' => 0])->progressPercent());
    }

    public function test_template_type_label_follows_attachment(): void
    {
        $this->assertSame('Text', MessageTemplate::factory()->create()->typeLabel());
        $this->assertSame('Text + Image', MessageTemplate::factory()->create(['attachment_id' => Media::factory()->create()->id])->typeLabel());
        $this->assertSame('Text + PDF', MessageTemplate::factory()->create(['attachment_id' => Media::factory()->pdf()->create()->id])->typeLabel());
    }

    public function test_template_tags_and_marathi_message_round_trip(): void
    {
        $message = "*📰 आजच्या चालू घडामोडी*\n\nनक्की वाचा. 🙏";
        $template = MessageTemplate::factory()->create(['message' => $message, 'tags' => ['current-affairs', 'mpsc']]);

        $fresh = $template->fresh();
        $this->assertSame($message, $fresh->message);
        $this->assertSame(['current-affairs', 'mpsc'], $fresh->tags);
    }

    public function test_media_human_size(): void
    {
        $this->assertSame('1.5 MB', Media::factory()->make(['size' => 1_572_864])->humanSize());
    }

    public function test_settings_store_json_values(): void
    {
        Setting::create(['key' => 'sending.delay_seconds', 'value' => 15]);
        Setting::create(['key' => 'media.allowed_types', 'value' => ['jpg', 'png', 'pdf']]);

        $this->assertSame(15, Setting::firstWhere('key', 'sending.delay_seconds')->value);
        $this->assertSame(['jpg', 'png', 'pdf'], Setting::firstWhere('key', 'media.allowed_types')->value);
    }

    public function test_whatsapp_session_defaults_to_one_disconnected_profile(): void
    {
        $session = WhatsAppSession::current();

        $this->assertSame(WhatsAppConnectionStatus::Disconnected, $session->status);
        $this->assertTrue($session->is(WhatsAppSession::current()));
        $this->assertDatabaseCount('whatsapp_sessions', 1);
    }
}

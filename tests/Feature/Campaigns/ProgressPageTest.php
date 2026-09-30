<?php

namespace Tests\Feature\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\SendErrorType;
use App\Enums\SendStatus;
use App\Jobs\StartCampaignJob;
use App\Livewire\Campaigns\Show;
use App\Livewire\Layout\HeaderStatus;
use App\Models\Campaign;
use App\Models\CampaignGroup;
use App\Models\SendLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ProgressPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Queue::fake();
        $this->actingAs(User::factory()->create());
    }

    private function sendingCampaign(): Campaign
    {
        $campaign = Campaign::factory()->create(['status' => CampaignStatus::Sending, 'started_at' => now(), 'total_groups' => 4, 'title' => 'Current Affairs — 30 Sep']);
        CampaignGroup::factory()->for($campaign)->sent()->create(['group_name' => 'MPSC Batch 01']);
        CampaignGroup::factory()->for($campaign)->create(['group_name' => 'MPSC Batch 02', 'status' => SendStatus::Processing]);
        CampaignGroup::factory()->for($campaign)->create(['group_name' => 'MPSC Batch 03']);
        CampaignGroup::factory()->for($campaign)->failed(SendErrorType::NotMember)->create(['group_name' => 'Police Batch 04']);

        return $campaign->refreshCounters();
    }

    public function test_progress_page_shows_live_counts_and_groups(): void
    {
        $campaign = $this->sendingCampaign();

        $this->get(route('campaigns.show', $campaign))
            ->assertOk()
            ->assertSeeLivewire(Show::class)
            ->assertSee('Sending Message')
            ->assertSee('Current Affairs — 30 Sep')
            ->assertSee('Estimated time remaining')
            ->assertSee('Police Batch 04')
            ->assertSee('Not a member')
            ->assertSee('wire:poll.2s.visible', false);

        $this->assertSame(
            ['sent' => 1, 'processing' => 1, 'pending' => 1, 'failed' => 1, 'skipped' => 0, 'cancelled' => 0],
            Livewire::test(Show::class, ['campaign' => $campaign])->instance()->counts,
        );
    }

    public function test_filter_tabs(): void
    {
        $campaign = $this->sendingCampaign();

        Livewire::test(Show::class, ['campaign' => $campaign])
            ->set('filter', 'failed')->assertSee('Police Batch 04')->assertDontSee('MPSC Batch 01')
            ->set('filter', 'pending')->assertSee('MPSC Batch 02')->assertSee('MPSC Batch 03')->assertDontSee('Police Batch 04')
            ->set('filter', 'sent')->assertSee('MPSC Batch 01')->assertDontSee('MPSC Batch 03');
    }

    public function test_pause_resume_and_cancel_buttons(): void
    {
        $campaign = $this->sendingCampaign();

        Livewire::test(Show::class, ['campaign' => $campaign])
            ->assertSee('Pause')
            ->call('pause')
            ->assertSee('Resume')
            ->assertSee('Paused');
        $this->assertSame(CampaignStatus::Paused, $campaign->fresh()->status);

        Livewire::test(Show::class, ['campaign' => $campaign->fresh()])->call('resume');
        $this->assertSame(CampaignStatus::Sending, $campaign->fresh()->status);

        Livewire::test(Show::class, ['campaign' => $campaign->fresh()])->call('cancel')->assertDispatched('close-modal');
        $this->assertSame(CampaignStatus::Cancelled, $campaign->fresh()->status);
    }

    public function test_automatic_pause_shows_the_reason(): void
    {
        $campaign = $this->sendingCampaign();
        $campaign->update(['status' => CampaignStatus::Paused, 'paused_at' => now()]);
        SendLog::factory()->create(['campaign_id' => $campaign->id, 'status' => SendStatus::Failed, 'error_type' => SendErrorType::WhatsAppDisconnected]);

        Livewire::test(Show::class, ['campaign' => $campaign])
            ->assertSee('Paused: WhatsApp disconnected')
            ->assertSee('Reconnect it from Settings');
    }

    public function test_stalled_campaign_offers_to_try_again(): void
    {
        $campaign = $this->sendingCampaign();
        CampaignGroup::query()->update(['updated_at' => now()->subMinutes(10)]);

        Livewire::test(Show::class, ['campaign' => $campaign])
            ->assertSee('Nothing has been sent for a while')
            ->call('restart')
            ->assertDispatched('toast');

        Queue::assertPushed(StartCampaignJob::class);
    }

    public function test_queued_campaign_says_it_is_waiting(): void
    {
        $this->sendingCampaign();
        $queued = Campaign::factory()->create(['status' => CampaignStatus::Queued, 'total_groups' => 1]);

        Livewire::test(Show::class, ['campaign' => $queued])->assertSee('Waiting in line');
    }

    public function test_finished_campaign_shows_details_without_controls(): void
    {
        $campaign = Campaign::factory()->create(['status' => CampaignStatus::Completed, 'completed_at' => now(), 'total_groups' => 1]);

        $this->get(route('campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('Campaign Details')
            ->assertDontSee('wire:poll.2s.visible', false)
            ->assertDontSee('Cancel campaign');
    }

    public function test_header_chip_links_to_the_progress_page(): void
    {
        $campaign = $this->sendingCampaign();

        Livewire::test(HeaderStatus::class)->assertSee(route('campaigns.show', $campaign), false);
    }
}

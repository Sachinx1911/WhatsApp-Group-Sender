<?php

namespace Tests\Feature\FailedMessages;

use App\Enums\CampaignStatus;
use App\Enums\SendErrorType;
use App\Enums\SendStatus;
use App\Jobs\SendToGroupJob;
use App\Jobs\StartCampaignJob;
use App\Livewire\Campaigns\Show;
use App\Livewire\FailedMessages\Index;
use App\Models\Campaign;
use App\Models\CampaignGroup;
use App\Models\Category;
use App\Models\Group;
use App\Models\SendLog;
use App\Models\User;
use App\Services\WhatsApp\FakeWhatsAppService;
use App\Services\WhatsApp\WhatsAppServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class FailedMessagesTest extends TestCase
{
    use RefreshDatabase;

    private FakeWhatsAppService $whatsapp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->actingAs(User::factory()->create());
        $this->whatsapp = new FakeWhatsAppService;
        $this->app->instance(WhatsAppServiceInterface::class, $this->whatsapp);
    }

    /** A finished campaign whose rows are [group name => error type or null for sent]. */
    private function campaign(array $rows, array $attributes = []): Campaign
    {
        $category = Category::factory()->create();
        $campaign = Campaign::factory()->create(['status' => CampaignStatus::PartiallyFailed, 'completed_at' => now(), 'total_groups' => count($rows), ...$attributes]);

        foreach ($rows as $name => $error) {
            $group = Group::firstWhere('name', $name) ?? Group::factory()->for($category)->create(['name' => $name]);
            $error
                ? CampaignGroup::factory()->for($campaign)->failed($error)->create(['group_id' => $group->id, 'group_name' => $name])
                : CampaignGroup::factory()->for($campaign)->sent()->create(['group_id' => $group->id, 'group_name' => $name]);
        }

        return $campaign->refreshCounters();
    }

    public function test_page_lists_failures_with_kpis(): void
    {
        $this->campaign([
            'MPSC 01' => null,
            'Police 04' => SendErrorType::NotMember,
            'Combined 03' => SendErrorType::Timeout,
            'Free 01' => SendErrorType::Unconfirmed,
        ], ['title' => 'Current Affairs — 30 Sep', 'message' => '*आजच्या चालू घडामोडी*']);

        $this->get('/failed')
            ->assertOk()
            ->assertSeeLivewire(Index::class)
            ->assertSee('View messages that failed to send, check error reasons and retry')
            ->assertSee('Police 04')->assertSee('Not a member')->assertSee('Needs fix')
            ->assertSee('Loading timeout')->assertSee('Temporary')
            ->assertSee('Delivery unconfirmed')->assertSee('Check first')
            ->assertSee('आजच्या चालू घडामोडी')
            ->assertDontSee('MPSC 01');

        $this->assertSame(
            ['failed' => 3, 'pending_retry' => 0, 'permanent' => 2, 'groups' => 3],
            Livewire::test(Index::class)->instance()->kpis,
        );
    }

    public function test_celebratory_empty_state(): void
    {
        Livewire::test(Index::class)->assertSee('No failed messages')->assertSee('All recent messages were sent successfully.');
    }

    public function test_filters_by_search_and_error(): void
    {
        $this->campaign(['Police 04' => SendErrorType::NotMember, 'Combined 03' => SendErrorType::Timeout], ['title' => 'Practice Paper']);

        Livewire::test(Index::class)
            ->set('search', 'Police')->assertSee('Police 04')->assertDontSee('Combined 03')
            ->set('search', 'Practice Paper')->assertSee('Police 04')->assertSee('Combined 03')
            ->set('search', '')->set('errorType', SendErrorType::Timeout->value)->assertSee('Combined 03')->assertDontSee('Police 04');

        Livewire::withQueryParams(['search' => 'Police 04'])->test(Index::class)->assertSee('Police 04')->assertDontSee('Combined 03');
    }

    public function test_retry_queues_the_group_and_reopens_the_campaign(): void
    {
        Queue::fake();
        $campaign = $this->campaign(['MPSC 01' => null, 'Combined 03' => SendErrorType::Timeout]);
        $row = CampaignGroup::where('group_name', 'Combined 03')->sole();

        Livewire::test(Index::class)->call('retry', $row->id)->assertDispatched('toast', type: 'success');

        $row->refresh();
        $this->assertSame(SendStatus::Pending, $row->status);
        $this->assertSame(0, $row->attempts);
        $this->assertSame(SendErrorType::Timeout, $row->error_type); // kept so "Retrying" shows why

        $campaign->refresh();
        $this->assertSame(CampaignStatus::Sending, $campaign->status);
        $this->assertNull($campaign->completed_at);
        $this->assertSame([1, 0, 1], [$campaign->sent_count, $campaign->failed_count, $campaign->pending_count]);
        Queue::assertPushed(StartCampaignJob::class, fn ($job) => $job->campaignId === $campaign->id);

        $this->assertSame(1, Livewire::test(Index::class)->instance()->kpis['pending_retry']);
        Livewire::test(Index::class)->set('view', 'retrying')->assertSee('Combined 03')->assertSee('Retrying');
    }

    public function test_retry_end_to_end_completes_the_campaign(): void
    {
        $campaign = $this->campaign(['MPSC 01' => null, 'Combined 03' => SendErrorType::Timeout]);

        Livewire::test(Index::class)->call('retry', CampaignGroup::where('group_name', 'Combined 03')->value('id'));

        $campaign->refresh();
        $this->assertSame(CampaignStatus::Completed, $campaign->status);
        $this->assertSame(['Combined 03'], array_column($this->whatsapp->sent, 'group'));
    }

    public function test_retry_waits_behind_the_campaign_that_is_sending(): void
    {
        Queue::fake();
        $old = $this->campaign(['Combined 03' => SendErrorType::Timeout]);
        Campaign::factory()->create(['status' => CampaignStatus::Sending]);

        Livewire::test(Index::class)->call('retry', CampaignGroup::where('campaign_id', $old->id)->value('id'));

        $this->assertSame(CampaignStatus::Queued, $old->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_retry_while_the_campaign_is_sending_queues_only_that_group(): void
    {
        Queue::fake();
        $campaign = $this->campaign(['Combined 03' => SendErrorType::Timeout, 'Other' => SendErrorType::Timeout], ['status' => CampaignStatus::Sending]);
        $row = CampaignGroup::where('group_name', 'Combined 03')->sole();

        Livewire::test(Index::class)->call('retry', $row->id);

        Queue::assertPushed(SendToGroupJob::class, 1);
        Queue::assertPushed(SendToGroupJob::class, fn ($job) => $job->campaignGroupId === $row->id);
        $this->assertSame(CampaignStatus::Sending, $campaign->fresh()->status);
    }

    public function test_skip_resolves_without_sending_and_updates_the_outcome(): void
    {
        $campaign = $this->campaign(['MPSC 01' => null, 'Police 04' => SendErrorType::NotMember]);

        Livewire::test(Index::class)->call('skip', CampaignGroup::where('group_name', 'Police 04')->value('id'));

        $campaign->refresh();
        $this->assertSame(CampaignStatus::Completed, $campaign->status); // partially failed → completed
        $this->assertSame([1, 0, 1], [$campaign->sent_count, $campaign->failed_count, $campaign->skipped_count]);
        $this->assertCount(0, $this->whatsapp->sent);

        Livewire::test(Index::class)->set('view', 'skipped')->assertSee('Police 04')->assertSee('Resolved');
    }

    public function test_bulk_retry_and_skip_selected(): void
    {
        Queue::fake();
        $this->campaign(['A' => SendErrorType::Timeout, 'B' => SendErrorType::NotMember, 'C' => SendErrorType::GroupNotFound]);
        [$a, $b, $c] = CampaignGroup::orderBy('id')->get()->all();

        Livewire::test(Index::class)
            ->set('selected', [(string) $a->id, (string) $b->id])
            ->call('retrySelected')
            ->assertSet('selected', [])
            ->assertDispatched('toast');

        $this->assertSame(SendStatus::Pending, $a->fresh()->status);
        $this->assertSame(SendStatus::Pending, $b->fresh()->status);
        $this->assertSame(SendStatus::Failed, $c->fresh()->status);

        Livewire::test(Index::class)->set('selectAll', true)->call('skipSelected');
        $this->assertSame(SendStatus::Skipped, $c->fresh()->status);
        $this->assertSame(SendStatus::Pending, $a->fresh()->status); // only failed rows are skipped
    }

    public function test_rows_that_are_not_failed_are_left_alone(): void
    {
        Queue::fake();
        $this->campaign(['MPSC 01' => null]);

        Livewire::test(Index::class)->call('retry', CampaignGroup::sole()->id)->assertDispatched('toast', type: 'info');

        $this->assertSame(SendStatus::Sent, CampaignGroup::sole()->status);
    }

    public function test_details_drawer_shows_error_group_info_and_technical_details(): void
    {
        $campaign = $this->campaign(['Free 01' => SendErrorType::Unconfirmed], ['title' => 'Current Affairs', 'attachment_name' => 'CA.pdf']);
        $row = CampaignGroup::sole();
        SendLog::factory()->create(['campaign_id' => $campaign->id, 'group_name' => 'Free 01', 'status' => SendStatus::Failed,
            'error_type' => SendErrorType::Unconfirmed, 'technical_details' => 'Row was still processing at 10:02']);

        Livewire::test(Index::class)
            ->call('openDetails', $row->id)
            ->assertDispatched('open-modal')
            ->assertSet('drawerAction', 'retry_after_fix') // not a temporary problem
            ->assertSee('Message Details')
            ->assertSee('Sending was interrupted')
            ->assertSee('If the message is already there, choose Skip')
            ->assertSee('Row was still processing at 10:02')
            ->assertSee('CA.pdf')
            ->assertSee('Retry Now')->assertSee('Retry After Fix')->assertSee('Skip This Message');
    }

    public function test_drawer_actions(): void
    {
        Queue::fake();
        $this->campaign(['A' => SendErrorType::Timeout, 'B' => SendErrorType::NotMember]);
        [$a, $b] = CampaignGroup::orderBy('id')->get()->all();

        Livewire::test(Index::class)->call('openDetails', $a->id)->assertSet('drawerAction', 'retry_now')->call('applyDrawerAction');
        $this->assertSame(SendStatus::Pending, $a->fresh()->status);

        Livewire::test(Index::class)->call('openDetails', $b->id)->set('drawerAction', 'skip')->call('applyDrawerAction');
        $this->assertSame(SendStatus::Skipped, $b->fresh()->status);
    }

    public function test_campaign_page_retries_all_its_failed_groups(): void
    {
        Queue::fake();
        $campaign = $this->campaign(['MPSC 01' => null, 'A' => SendErrorType::Timeout, 'B' => SendErrorType::NotMember]);

        Livewire::test(Show::class, ['campaign' => $campaign])
            ->assertSee('Retry failed (2)')
            ->call('retryFailed')
            ->assertDispatched('toast');

        $this->assertSame(2, $campaign->campaignGroups()->where('status', SendStatus::Pending)->count());
    }
}

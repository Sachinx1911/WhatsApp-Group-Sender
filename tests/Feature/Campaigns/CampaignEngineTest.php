<?php

namespace Tests\Feature\Campaigns;

use App\Actions\Campaigns\CreateCampaign;
use App\Enums\CampaignStatus;
use App\Enums\SendErrorType;
use App\Enums\SendStatus;
use App\Jobs\SendToGroupJob;
use App\Jobs\StartCampaignJob;
use App\Models\AppNotification;
use App\Models\Campaign;
use App\Models\CampaignGroup;
use App\Models\Category;
use App\Models\Group;
use App\Models\Media;
use App\Models\MessageTemplate;
use App\Models\SendLog;
use App\Services\Campaigns\CampaignRunner;
use App\Services\WhatsApp\FakeWhatsAppService;
use App\Services\WhatsApp\WhatsAppServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CampaignEngineTest extends TestCase
{
    use RefreshDatabase;

    private FakeWhatsAppService $whatsapp;

    private int $groupNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->whatsapp = new FakeWhatsAppService;
        $this->app->instance(WhatsAppServiceInterface::class, $this->whatsapp);
    }

    /** @return Collection<int, Group> */
    private function makeGroups(int $count, array $names = []): Collection
    {
        $category = Category::factory()->create();

        return collect(range(1, $count))->map(fn ($i) => Group::factory()->for($category)->create([
            'name' => $names[$i - 1] ?? sprintf('Batch %02d', ++$this->groupNumber),
        ]));
    }

    private function create(Collection $groups, array $options = []): Campaign
    {
        return app(CreateCampaign::class)->handle(
            message: $options['message'] ?? '*आजच्या चालू घडामोडी*',
            attachment: $options['attachment'] ?? null,
            groupIds: $groups->pluck('id')->all(),
            isTest: $options['test'] ?? false,
            template: $options['template'] ?? null,
        );
    }

    private function runJob(int $rowId): void
    {
        app()->call([new SendToGroupJob($rowId), 'handle']);
    }

    public function test_campaign_sends_to_every_group_and_completes(): void
    {
        $groups = $this->makeGroups(3);
        $pdf = Media::factory()->pdf()->create(['original_name' => 'CA.pdf']);
        $template = MessageTemplate::factory()->create(['title' => 'Current Affairs']);

        $campaign = $this->create($groups, ['attachment' => $pdf, 'template' => $template]);

        $this->assertSame(CampaignStatus::Completed, $campaign->status);
        $this->assertSame([3, 3, 0, 0], [$campaign->total_groups, $campaign->sent_count, $campaign->failed_count, $campaign->pending_count]);
        $this->assertNotNull($campaign->started_at);
        $this->assertNotNull($campaign->completed_at);
        $this->assertStringStartsWith('Current Affairs — ', $campaign->title);
        $this->assertSame('CA.pdf', $campaign->attachment_name);

        $this->assertCount(3, $this->whatsapp->sent);
        $this->assertSame('*आजच्या चालू घडामोडी*', $this->whatsapp->sent[0]['message']);
        $this->assertSame('CA.pdf', $this->whatsapp->sent[0]['attachment']);

        $this->assertSame(3, CampaignGroup::where('status', SendStatus::Sent)->whereNotNull('sent_at')->count());
        $this->assertSame(0, Group::whereNull('last_sent_at')->count());
        $this->assertSame(3, SendLog::where('status', SendStatus::Sent)->count());
        $this->assertSame(1, $template->fresh()->usage_count);
        $this->assertSame(1, $pdf->fresh()->usage_count);
        $this->assertTrue(AppNotification::where('type', 'campaign_completed')->exists());
    }

    public function test_test_campaigns_do_not_count_as_template_or_media_usage(): void
    {
        $template = MessageTemplate::factory()->create();
        $media = Media::factory()->create();

        $campaign = $this->create($this->makeGroups(1), ['test' => true, 'template' => $template, 'attachment' => $media]);

        $this->assertTrue($campaign->is_test);
        $this->assertStringStartsWith('Test — ', $campaign->title);
        $this->assertSame(0, $template->fresh()->usage_count);
        $this->assertSame(0, $media->fresh()->usage_count);
    }

    public function test_permanent_failures_are_not_retried(): void
    {
        $groups = $this->makeGroups(3, ['MPSC 01', 'Police 04', 'MPSC 02']);
        $this->whatsapp->failFor('Police 04', SendErrorType::NotMember);

        $campaign = $this->create($groups);

        $this->assertSame(CampaignStatus::PartiallyFailed, $campaign->status);
        $this->assertSame([2, 1], [$campaign->sent_count, $campaign->failed_count]);

        $row = CampaignGroup::where('group_name', 'Police 04')->sole();
        $this->assertSame(SendStatus::Failed, $row->status);
        $this->assertSame(SendErrorType::NotMember, $row->error_type);
        $this->assertSame(1, $row->attempts);
        $this->assertTrue(AppNotification::where('type', 'campaign_failures')->exists());
    }

    public function test_campaign_where_every_group_fails_is_failed(): void
    {
        $this->whatsapp->failFor('Only', SendErrorType::GroupNotFound);

        $this->assertSame(CampaignStatus::Failed, $this->create($this->makeGroups(1, ['Only']))->status);
    }

    public function test_temporary_problems_are_retried_up_to_the_limit(): void
    {
        $this->whatsapp->failFor('Slow', SendErrorType::Timeout);
        $campaign = $this->create($this->makeGroups(1, ['Slow']));
        $row = CampaignGroup::sole();

        // First attempt failed and the job was released for a later retry.
        $this->assertSame(SendStatus::Pending, $row->fresh()->status);
        $this->assertSame(1, $row->fresh()->attempts);
        $this->assertSame(CampaignStatus::Sending, $campaign->fresh()->status);

        $this->runJob($row->id); // attempt 2
        $this->assertSame(SendStatus::Pending, $row->fresh()->status);

        $this->runJob($row->id); // attempt 3: give up
        $this->assertSame(SendStatus::Failed, $row->fresh()->status);
        $this->assertSame(3, $row->fresh()->attempts);
        $this->assertSame(CampaignStatus::Failed, $campaign->fresh()->status);
    }

    public function test_retry_succeeds_when_the_problem_goes_away(): void
    {
        $this->whatsapp->failFor('Flaky', SendErrorType::MediaUploadFailed);
        $campaign = $this->create($this->makeGroups(1, ['Flaky']));

        $this->app->instance(WhatsAppServiceInterface::class, new FakeWhatsAppService);
        $this->runJob(CampaignGroup::sole()->id);

        $this->assertSame(CampaignStatus::Completed, $campaign->fresh()->status);
        $this->assertSame(2, CampaignGroup::sole()->attempts);
    }

    public function test_disconnect_pauses_the_campaign_and_resume_continues(): void
    {
        $this->whatsapp->disconnect();
        $campaign = $this->create($this->makeGroups(3));

        $campaign->refresh();
        $this->assertSame(CampaignStatus::Paused, $campaign->status);
        $this->assertNotNull($campaign->paused_at);
        $this->assertSame(3, CampaignGroup::where('status', SendStatus::Pending)->where('attempts', 0)->count());
        $this->assertTrue(AppNotification::where('type', 'whatsapp_disconnected')->exists());

        $reconnected = new FakeWhatsAppService;
        $reconnected->connect();
        $this->app->instance(WhatsAppServiceInterface::class, $reconnected);
        app(CampaignRunner::class)->resume($campaign);

        $this->assertSame(CampaignStatus::Completed, $campaign->fresh()->status);
        $this->assertCount(3, $reconnected->sent);
    }

    public function test_a_group_is_never_sent_twice(): void
    {
        $campaign = $this->create($this->makeGroups(2));
        $rows = CampaignGroup::pluck('id');

        // Duplicate jobs (e.g. after a resume or a restart) do nothing.
        $rows->each(fn ($id) => $this->runJob($id));
        app(CampaignRunner::class)->recover();

        $this->assertCount(2, $this->whatsapp->sent);
        $this->assertSame(CampaignStatus::Completed, $campaign->fresh()->status);
    }

    public function test_a_row_being_processed_is_not_claimed_again(): void
    {
        Queue::fake();
        $campaign = $this->create($this->makeGroups(1));
        $row = CampaignGroup::sole();
        $row->update(['status' => SendStatus::Processing]);

        $this->runJob($row->id);

        $this->assertCount(0, $this->whatsapp->sent);
        $this->assertSame(CampaignStatus::Sending, $campaign->fresh()->status);
    }

    public function test_pause_stops_remaining_groups_until_resumed(): void
    {
        Queue::fake();
        $campaign = $this->create($this->makeGroups(3));
        Queue::assertPushed(StartCampaignJob::class);

        app()->call([new StartCampaignJob($campaign->id), 'handle']);
        $rowIds = CampaignGroup::orderBy('id')->pluck('id');
        Queue::assertPushed(SendToGroupJob::class, 3);

        $this->runJob($rowIds[0]);
        app(CampaignRunner::class)->pause($campaign);
        $this->runJob($rowIds[1]); // skipped while paused

        $this->assertCount(1, $this->whatsapp->sent);
        $this->assertSame(CampaignStatus::Paused, $campaign->fresh()->status);

        app(CampaignRunner::class)->resume($campaign);
        $this->assertSame(CampaignStatus::Sending, $campaign->fresh()->status);

        $this->runJob($rowIds[1]);
        $this->runJob($rowIds[2]);
        $this->assertSame(CampaignStatus::Completed, $campaign->fresh()->status);
    }

    public function test_only_one_campaign_sends_at_a_time(): void
    {
        Queue::fake();
        $first = $this->create($this->makeGroups(1));
        $second = $this->create($this->makeGroups(1));

        $this->assertSame(CampaignStatus::Sending, $first->status);
        $this->assertSame(CampaignStatus::Queued, $second->status);

        $this->runJob($first->campaignGroups()->value('id'));

        $this->assertSame(CampaignStatus::Completed, $first->fresh()->status);
        $this->assertSame(CampaignStatus::Sending, $second->fresh()->status);
        Queue::assertPushed(StartCampaignJob::class, fn ($job) => $job->campaignId === $second->id);
    }

    public function test_a_paused_campaign_still_holds_the_line(): void
    {
        Queue::fake();
        $first = $this->create($this->makeGroups(1));
        app(CampaignRunner::class)->pause($first);

        $second = $this->create($this->makeGroups(1));

        $this->assertSame(CampaignStatus::Queued, $second->status);
    }

    public function test_cancel_stops_pending_groups_and_starts_the_next_campaign(): void
    {
        Queue::fake();
        $first = $this->create($this->makeGroups(3));
        $second = $this->create($this->makeGroups(1));
        $this->runJob($first->campaignGroups()->orderBy('id')->value('id'));

        app(CampaignRunner::class)->cancel($first);

        $first->refresh();
        $this->assertSame(CampaignStatus::Cancelled, $first->status);
        $this->assertSame([1, 0], [$first->sent_count, $first->pending_count]);
        $this->assertSame(2, $first->campaignGroups()->where('status', SendStatus::Cancelled)->count());
        $this->assertSame(CampaignStatus::Sending, $second->fresh()->status);
    }

    public function test_daily_limit_pauses_sending(): void
    {
        config(['educationhub.sending.daily_limit' => 1]);

        $campaign = $this->create($this->makeGroups(2));

        $this->assertSame(CampaignStatus::Paused, $campaign->fresh()->status);
        $this->assertCount(1, $this->whatsapp->sent);
        $this->assertTrue(AppNotification::where('title', 'like', '%Daily limit reached%')->exists());
    }

    public function test_deleted_group_fails_with_group_not_found(): void
    {
        Queue::fake();
        $groups = $this->makeGroups(1);
        $campaign = $this->create($groups);
        $groups->first()->delete();

        $this->runJob(CampaignGroup::sole()->id);

        $this->assertSame(SendErrorType::GroupNotFound, CampaignGroup::sole()->error_type);
        $this->assertSame(CampaignStatus::Failed, $campaign->fresh()->status);
    }

    public function test_recover_marks_interrupted_sends_as_unconfirmed_and_restarts_campaigns(): void
    {
        Queue::fake();
        $campaign = $this->create($this->makeGroups(3));
        [$stuck, $fresh] = CampaignGroup::orderBy('id')->take(2)->get();
        $stuck->forceFill(['status' => SendStatus::Processing, 'updated_at' => now()->subMinutes(10)])->saveQuietly();
        $fresh->forceFill(['status' => SendStatus::Processing, 'updated_at' => now()])->saveQuietly();

        $this->artisan('campaigns:recover')->assertSuccessful();

        $this->assertSame(SendStatus::Failed, $stuck->fresh()->status);
        $this->assertSame(SendErrorType::Unconfirmed, $stuck->fresh()->error_type);
        $this->assertSame(SendStatus::Processing, $fresh->fresh()->status); // still within the allowance
        $this->assertTrue(SendLog::where('error_type', SendErrorType::Unconfirmed)->exists());
        Queue::assertPushed(StartCampaignJob::class, fn ($job) => $job->campaignId === $campaign->id);
        $this->assertCount(0, $this->whatsapp->sent); // unconfirmed rows are never re-sent automatically
    }

    public function test_job_crash_is_recorded_so_the_campaign_can_finish(): void
    {
        Queue::fake();
        $campaign = $this->create($this->makeGroups(1));
        $row = CampaignGroup::sole();
        $row->update(['status' => SendStatus::Processing]);

        (new SendToGroupJob($row->id))->failed(new \RuntimeException('Timed out'));

        $this->assertSame(SendErrorType::Unconfirmed, $row->fresh()->error_type);
        $this->assertSame(CampaignStatus::Failed, $campaign->fresh()->status);
    }

    public function test_only_active_groups_are_included(): void
    {
        $groups = $this->makeGroups(2);
        $groups->last()->update(['status' => 'inactive']);

        $this->assertSame(1, $this->create($groups)->total_groups);
    }
}

<?php

namespace Tests\Feature\History;

use App\Enums\CampaignStatus;
use App\Enums\SendStatus;
use App\Livewire\Campaigns\Show;
use App\Livewire\History\Index;
use App\Livewire\SendMessage\Compose;
use App\Models\Campaign;
use App\Models\CampaignGroup;
use App\Models\Category;
use App\Models\Group;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class SendHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Category $mpsc;

    private Category $police;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Carbon::setTestNow('2026-09-30 14:00:00');
        $this->actingAs(User::factory()->create());
        $this->mpsc = Category::factory()->create(['name' => 'MPSC']);
        $this->police = Category::factory()->create(['name' => 'Police Bharti']);
    }

    /** A finished campaign with rows for the given groups: [group, status]. */
    private function campaign(array $attributes, array $rows = []): Campaign
    {
        $campaign = Campaign::factory()->create(['status' => CampaignStatus::Completed, 'completed_at' => now(), ...$attributes]);

        foreach ($rows as [$group, $status]) {
            CampaignGroup::factory()->for($campaign)->create([
                'group_id' => $group->id, 'group_name' => $group->name, 'status' => $status,
                'sent_at' => $status === SendStatus::Sent ? now() : null,
            ]);
        }

        $campaign->refreshCounters()->update(['total_groups' => count($rows)]);

        return $campaign;
    }

    public function test_page_lists_campaigns_newest_first_with_kpis(): void
    {
        $mpsc01 = Group::factory()->for($this->mpsc)->create(['name' => 'MPSC Batch 01']);
        $police04 = Group::factory()->for($this->police)->create(['name' => 'Police Batch 04']);

        $this->campaign(['title' => 'Old campaign', 'created_at' => now()->subDays(3)], [[$mpsc01, SendStatus::Sent]]);
        $this->campaign(['title' => 'Current Affairs — 30 Sep', 'message' => '*आजच्या चालू घडामोडी*', 'attachment_name' => 'CA.pdf', 'status' => CampaignStatus::PartiallyFailed],
            [[$mpsc01, SendStatus::Sent], [$police04, SendStatus::Failed]]);

        $this->get('/history')
            ->assertOk()
            ->assertSeeLivewire(Index::class)
            ->assertSee('View all previously sent campaigns')
            ->assertSeeInOrder(['Current Affairs — 30 Sep', 'Old campaign'])
            ->assertSee('आजच्या चालू घडामोडी')
            ->assertSee('CA.pdf')
            ->assertSee('Partially Failed');

        $this->assertSame(
            ['campaigns' => 2, 'sent' => 2, 'failed' => 1, 'groups_reached' => 1],
            Livewire::test(Index::class)->instance()->kpis,
        );
    }

    public function test_search_by_campaign_message_or_group(): void
    {
        $police04 = Group::factory()->for($this->police)->create(['name' => 'Police Batch 04']);
        $mpsc01 = Group::factory()->for($this->mpsc)->create(['name' => 'MPSC Batch 01']);
        $this->campaign(['title' => 'Holiday Notice', 'message' => 'सुट्टी सूचना'], [[$mpsc01, SendStatus::Sent]]);
        $this->campaign(['title' => 'Practice Paper', 'message' => 'सराव पेपर'], [[$police04, SendStatus::Failed]]);

        Livewire::test(Index::class)
            ->set('search', 'Holiday')->assertSee('Holiday Notice')->assertDontSee('Practice Paper')
            ->set('search', 'सराव')->assertSee('Practice Paper')->assertDontSee('Holiday Notice')
            ->set('search', 'Police Batch 04')->assertSee('Practice Paper')->assertDontSee('Holiday Notice');
    }

    public function test_search_from_the_global_search_link(): void
    {
        $this->campaign(['title' => 'Holiday Notice']);
        $this->campaign(['title' => 'Practice Paper']);

        Livewire::withQueryParams(['search' => 'Holiday Notice'])->test(Index::class)
            ->assertSee('Holiday Notice')->assertDontSee('Practice Paper');
    }

    public function test_filters_by_category_status_date_and_tests(): void
    {
        $mpsc = Group::factory()->for($this->mpsc)->create();
        $police = Group::factory()->for($this->police)->create();
        $this->campaign(['title' => 'MPSC campaign', 'created_at' => now()->subDays(10)], [[$mpsc, SendStatus::Sent]]);
        $this->campaign(['title' => 'Police campaign', 'status' => CampaignStatus::Failed, 'created_at' => now()->subDays(2)], [[$police, SendStatus::Failed]]);
        $this->campaign(['title' => 'Test send', 'is_test' => true], [[$mpsc, SendStatus::Sent]]);
        Campaign::factory()->create(['title' => 'Running now', 'status' => CampaignStatus::Paused]);

        Livewire::test(Index::class)
            ->set('category', (string) $this->police->id)->assertSee('Police campaign')->assertDontSee('MPSC campaign')
            ->call('clearFilters')
            ->set('status', 'failed')->assertSee('Police campaign')->assertDontSee('MPSC campaign')
            ->set('status', 'in_progress')->assertSee('Running now')->assertDontSee('Police campaign')
            ->call('clearFilters')
            ->set('range', 'today')->assertSee('Test send')->assertDontSee('Police campaign')
            ->set('range', '7d')->assertSee('Police campaign')->assertDontSee('MPSC campaign')
            ->set('range', 'custom')->set('from', now()->subDays(11)->toDateString())->set('to', now()->subDays(9)->toDateString())
            ->assertSee('MPSC campaign')->assertDontSee('Police campaign')
            ->call('clearFilters')
            ->set('hideTests', true)->assertDontSee('Test send')->assertSee('MPSC campaign');
    }

    public function test_invalid_custom_dates_are_ignored(): void
    {
        $this->campaign(['title' => 'Kept']);

        Livewire::test(Index::class)
            ->set('range', 'custom')->set('from', 'not-a-date')
            ->assertSee('Kept');
    }

    public function test_kpis_follow_the_filters(): void
    {
        $group = Group::factory()->for($this->mpsc)->create();
        $this->campaign(['title' => 'A', 'created_at' => now()->subDays(20)], [[$group, SendStatus::Sent]]);
        $this->campaign(['title' => 'B'], [[$group, SendStatus::Failed]]);

        $kpis = Livewire::test(Index::class)->set('range', 'today')->instance()->kpis;

        $this->assertSame(['campaigns' => 1, 'sent' => 0, 'failed' => 1, 'groups_reached' => 0], $kpis);
    }

    public function test_sorting_and_pagination(): void
    {
        Campaign::factory()->count(30)->sequence(fn ($s) => ['title' => sprintf('Campaign %02d', $s->index + 1), 'created_at' => now()->subMinutes($s->index)])->create();

        Livewire::test(Index::class)
            ->assertSee('Campaign 01')->assertDontSee('Campaign 26')
            ->call('sortBy', 'created_at') // newest first → oldest first
            ->assertSee('Campaign 30')->assertDontSee('Campaign 05');
    }

    public function test_empty_states(): void
    {
        Livewire::test(Index::class)->assertSee('Nothing sent yet');

        $this->campaign(['title' => 'Something']);
        Livewire::test(Index::class)->set('search', 'zzz')->assertSee('No campaigns match your filters');
    }

    public function test_campaign_details_timeline_and_send_again(): void
    {
        $group = Group::factory()->for($this->mpsc)->create();
        $campaign = $this->campaign(['title' => 'Current Affairs', 'started_at' => now()->subMinutes(5)], [[$group, SendStatus::Sent]]);

        $response = $this->get(route('campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('Back to Send History')
            ->assertSee('Timeline')
            ->assertSee('Sending started')
            ->assertSee('First group received it')
            ->assertSee(route('send.create', ['campaign' => $campaign->id]), false);

        // Campaign details live under "Send History" in the sidebar.
        $this->assertMatchesRegularExpression('/title="Send History"\s+aria-current="page"/', $response->getContent());

        $labels = array_column(Livewire::test(Show::class, ['campaign' => $campaign])->instance()->timeline, 'label');
        $this->assertSame(['Campaign created', 'Sending started', 'First group received it', 'Completed'], $labels);
    }

    public function test_send_again_prefills_message_attachment_and_active_groups(): void
    {
        $media = Media::factory()->create();
        $active = Group::factory()->for($this->mpsc)->create();
        $inactive = Group::factory()->for($this->mpsc)->inactive()->create();
        $campaign = $this->campaign(['message' => 'पुन्हा पाठवा', 'attachment_id' => $media->id], [[$active, SendStatus::Sent], [$inactive, SendStatus::Sent]]);

        Livewire::withQueryParams(['campaign' => $campaign->id])->test(Compose::class)
            ->assertSet('form.message', 'पुन्हा पाठवा')
            ->assertSet('form.attachment_id', $media->id)
            ->assertSet('form.groups', [(string) $active->id]);
    }
}

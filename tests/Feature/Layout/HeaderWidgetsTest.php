<?php

namespace Tests\Feature\Layout;

use App\Enums\SendErrorType;
use App\Livewire\Layout\GlobalSearch;
use App\Livewire\Layout\NotificationBell;
use App\Models\AppNotification;
use App\Models\Campaign;
use App\Models\CampaignGroup;
use App\Models\Category;
use App\Models\Group;
use App\Models\MessageTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HeaderWidgetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_search_needs_at_least_two_characters(): void
    {
        Group::factory()->create(['name' => 'MPSC Batch 01']);

        $this->assertSame([], Livewire::test(GlobalSearch::class)->set('query', 'M')->instance()->sections);
    }

    public function test_search_finds_groups_by_name_or_category(): void
    {
        $police = Category::factory()->create(['name' => 'Police Bharti']);
        Group::factory()->for($police)->create(['name' => 'Evening Batch']);
        Group::factory()->create(['name' => 'MPSC Batch 01']);

        $sections = collect(Livewire::test(GlobalSearch::class)->set('query', 'police')->instance()->sections);

        $groups = $sections->firstWhere('label', 'Groups');
        $this->assertSame(['Evening Batch'], array_column($groups['items'], 'title'));
    }

    public function test_search_finds_campaigns_and_templates_by_marathi_message_text(): void
    {
        Campaign::factory()->create(['title' => 'Current Affairs — 30 Sep', 'message' => 'आजच्या चालू घडामोडी']);
        MessageTemplate::factory()->create(['title' => 'Daily CA', 'message' => '*📰 आजच्या चालू घडामोडी*']);
        Campaign::factory()->create(['title' => 'Holiday', 'message' => 'सुट्टी सूचना']);

        Livewire::test(GlobalSearch::class)
            ->set('query', 'घडामोडी')
            ->assertSee('Current Affairs — 30 Sep')
            ->assertSee('Daily CA')
            ->assertDontSee('Holiday');
    }

    public function test_search_finds_failures_by_error_message(): void
    {
        CampaignGroup::factory()->failed(SendErrorType::NotMember)->create(['group_name' => 'Police Batch 04']);
        CampaignGroup::factory()->sent()->create(['group_name' => 'Police Batch 05']);

        $sections = collect(Livewire::test(GlobalSearch::class)->set('query', 'Not a member')->instance()->sections);

        $failed = $sections->firstWhere('label', 'Failed messages');
        $this->assertSame(['Police Batch 04'], array_column($failed['items'], 'title'));
    }

    public function test_search_shows_a_friendly_message_when_nothing_matches(): void
    {
        Livewire::test(GlobalSearch::class)
            ->set('query', 'zzzz')
            ->assertSee('No results for');
    }

    public function test_notification_bell_counts_unread_and_marks_all_read(): void
    {
        AppNotification::create(['type' => 'campaign_completed', 'title' => 'Campaign completed']);
        AppNotification::create(['type' => 'campaign_failures', 'title' => 'Some messages failed']);
        AppNotification::create(['type' => 'campaign_completed', 'title' => 'Old one', 'read_at' => now()]);

        Livewire::test(NotificationBell::class)
            ->assertSee('Notifications (2 unread)')
            ->assertSee('Some messages failed')
            ->call('markAllRead')
            ->assertDontSee('unread)');

        $this->assertSame(0, AppNotification::unread()->count());
    }

    public function test_opening_a_notification_marks_it_read_and_follows_its_link(): void
    {
        $notification = AppNotification::create(['type' => 'campaign_failures', 'title' => 'Failed', 'url' => '/failed']);

        Livewire::test(NotificationBell::class)
            ->call('open', $notification->id)
            ->assertRedirect('/failed');

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_empty_notification_list(): void
    {
        Livewire::test(NotificationBell::class)->assertSee('all caught up');
    }
}

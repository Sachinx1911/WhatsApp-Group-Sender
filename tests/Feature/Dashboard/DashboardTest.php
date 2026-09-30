<?php

namespace Tests\Feature\Dashboard;

use App\Enums\CampaignStatus;
use App\Enums\SendErrorType;
use App\Enums\SendStatus;
use App\Livewire\Dashboard\Index;
use App\Models\Campaign;
use App\Models\CampaignGroup;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Carbon::setTestNow('2026-09-30 14:00:00');
        $this->actingAs(User::factory()->create());
    }

    public function test_dashboard_renders_inside_the_app_shell(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSeeLivewire(Index::class)
            ->assertSee('Overview of your WhatsApp education content distribution')
            ->assertSee('Quick Actions')
            ->assertSee('Storage Usage');
    }

    public function test_kpis_come_from_the_database(): void
    {
        Group::factory()->count(3)->create();
        Group::factory()->inactive()->create();

        $campaign = Campaign::factory()->create(['status' => CampaignStatus::Sending]);
        CampaignGroup::factory()->for($campaign)->sent()->count(2)->create(['sent_at' => now()->setTime(9, 15)]);
        CampaignGroup::factory()->for($campaign)->sent()->create(['sent_at' => now()->subDay()]);
        CampaignGroup::factory()->for($campaign)->count(2)->create(['status' => SendStatus::Pending]);
        CampaignGroup::factory()->for($campaign)->failed(SendErrorType::NotMember)->create();

        $stats = Livewire::test(Index::class)->instance()->stats;

        $this->assertSame(10, $stats['groups_total']); // 4 created above + 6 created by the row factories
        $this->assertSame(9, $stats['groups_active']);
        $this->assertSame(2, $stats['sent_today']);
        $this->assertSame(1, $stats['sent_yesterday']);
        $this->assertSame(2, $stats['pending']);
        $this->assertSame(1, $stats['active_campaigns']);
        $this->assertSame(1, $stats['failed']);
        $this->assertSame('disconnected', $stats['session']->status->value);
    }

    public function test_chart_counts_sent_and_failed_per_hour_today(): void
    {
        CampaignGroup::factory()->sent()->count(3)->create(['sent_at' => now()->setTime(8, 10)]);
        CampaignGroup::factory()->sent()->create(['sent_at' => now()->setTime(11, 45)]);
        CampaignGroup::factory()->sent()->create(['sent_at' => now()->subDay()->setTime(8, 0)]); // not today
        CampaignGroup::factory()->failed()->create(['updated_at' => now()->setTime(11, 5)]);

        $chart = Livewire::test(Index::class)->instance()->chart;

        $this->assertSame('6 AM', $chart['labels'][0]);
        $this->assertSame('10 PM', end($chart['labels']));
        $this->assertSame(3, $chart['series'][0]['data'][2]);  // 8 AM
        $this->assertSame(1, $chart['series'][0]['data'][5]);  // 11 AM
        $this->assertSame(1, $chart['series'][1]['data'][5]);  // failed at 11 AM
        $this->assertSame(['sent' => 4, 'failed' => 1], $chart['totals']);
    }

    public function test_chart_widens_for_activity_outside_the_default_hours(): void
    {
        CampaignGroup::factory()->sent()->create(['sent_at' => now()->setTime(5, 30)]);

        $labels = Livewire::test(Index::class)->instance()->chart['labels'];

        $this->assertSame('5 AM', $labels[0]);
    }

    public function test_recent_campaigns_table_shows_latest_campaigns(): void
    {
        Campaign::factory()->create(['title' => 'Old campaign', 'created_at' => now()->subDays(10)]);
        foreach (range(1, Index::RECENT_CAMPAIGNS) as $i) {
            Campaign::factory()->create(['title' => "Campaign {$i}", 'created_at' => now()->subMinutes($i)]);
        }
        Campaign::factory()->test()->create(['title' => 'Today test', 'message' => 'आजच्या चालू घडामोडी']);

        Livewire::test(Index::class)
            ->assertSee('Today test')
            ->assertSee('आजच्या चालू घडामोडी')
            ->assertSee('Campaign 1')
            ->assertDontSee('Old campaign');
    }

    public function test_empty_states_when_nothing_has_been_sent(): void
    {
        Livewire::test(Index::class)
            ->assertSee('No sending activity today')
            ->assertSee('No campaigns yet');
    }

    public function test_polling_refresh_pushes_new_chart_data(): void
    {
        Livewire::test(Index::class)
            ->call('refresh')
            ->assertDispatched('activity-updated');
    }
}

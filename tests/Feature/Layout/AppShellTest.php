<?php

namespace Tests\Feature\Layout;

use App\Enums\CampaignStatus;
use App\Livewire\Layout\HeaderStatus;
use App\Models\Campaign;
use App\Models\CampaignGroup;
use App\Models\User;
use App\Support\Navigation;
use App\Support\StorageUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AppShellTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_sidebar_lists_every_screen_and_marks_the_current_one(): void
    {
        $response = $this->actingAs(User::factory()->create(['name' => 'Admin']))->get('/groups');

        $response->assertOk()->assertSee('Education Hub')->assertSee('WhatsApp Group Sender');
        foreach (Navigation::items() as $item) {
            $response->assertSee(route($item['route']), false)->assertSee($item['label']);
        }
        $this->assertMatchesRegularExpression('/title="Group Manager"\s+aria-current="page"/', $response->getContent());
        $this->assertSame(1, substr_count($response->getContent(), 'aria-current="page"'));
    }

    public function test_every_screen_requires_login_and_opens_when_signed_in(): void
    {
        foreach (Navigation::items() as $item) {
            $this->get(route($item['route']))->assertRedirect('/login');
        }

        $this->actingAs(User::factory()->create());

        foreach (Navigation::items() as $item) {
            $this->get(route($item['route']))->assertOk()->assertSee($item['label']);
        }
    }

    public function test_failed_messages_badge_shows_open_failures(): void
    {
        CampaignGroup::factory()->failed()->count(3)->create();
        CampaignGroup::factory()->sent()->create();

        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertSeeInOrder(['Failed Messages', '3']);
    }

    public function test_header_shows_admin_menu_with_logout(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Sachin', 'email' => 'admin@educationhub.local']))
            ->get('/')
            ->assertSee('Sachin')
            ->assertSee('admin@educationhub.local')
            ->assertSee(route('logout'), false);
    }

    public function test_header_status_shows_whatsapp_state_and_active_campaign(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(HeaderStatus::class)
            ->assertSee('WhatsApp Disconnected')
            ->assertDontSee('Sending');

        Campaign::factory()->create([
            'status' => CampaignStatus::Sending, 'total_groups' => 250, 'sent_count' => 142, 'failed_count' => 5,
        ]);

        Livewire::test(HeaderStatus::class)->assertSee('Sending 147/250');
    }

    public function test_storage_usage_measures_media_files(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('media/a.pdf', str_repeat('x', 1024 * 1024));
        Storage::disk('local')->put('media/demo/b.pdf', str_repeat('x', 1024 * 1024));
        Storage::disk('local')->put('other/ignored.txt', str_repeat('x', 5000));
        config(['educationhub.storage_quota_gb' => 1]);
        StorageUsage::forget();

        $summary = StorageUsage::summary();

        $this->assertSame(2 * 1024 * 1024, $summary['used']);
        $this->assertSame(1024 ** 3, $summary['quota']);
        $this->assertSame(0.2, $summary['percent']);
        $this->assertSame('2.0 MB', $summary['used_label']);
    }
}

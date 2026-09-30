<?php

namespace Tests\Feature\Database;

use App\Models\Campaign;
use App\Models\Category;
use App\Models\Group;
use App\Models\Media;
use App\Models\MessageTemplate;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoCampaignSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SeedersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['educationhub.admin' => ['name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'test-password']]);

        $this->seed(DatabaseSeeder::class);
    }

    public function test_admin_account_is_created_from_config(): void
    {
        $admin = User::sole();

        $this->assertSame('admin@test.local', $admin->email);
        $this->assertTrue(Hash::check('test-password', $admin->password));
    }

    public function test_default_categories_and_at_least_30_groups(): void
    {
        $this->assertSame(
            ['MPSC', 'Police Bharti', 'Combined', 'Free', 'Premium', 'Other'],
            Category::ordered()->pluck('name')->all(),
        );
        $this->assertGreaterThanOrEqual(30, Group::count());
        $this->assertTrue(Group::where('name', 'Education Hub Test Group')->exists());
        $this->assertGreaterThan(0, Group::where('status', 'inactive')->count());
    }

    public function test_campaign_counters_match_their_rows(): void
    {
        $campaigns = Campaign::with('campaignGroups')->get();
        $this->assertGreaterThanOrEqual(10, $campaigns->count());

        foreach ($campaigns as $campaign) {
            $rows = $campaign->campaignGroups->countBy(fn ($row) => $row->status->value);

            $this->assertSame($campaign->total_groups, $campaign->campaignGroups->count(), $campaign->title);
            $this->assertSame($campaign->sent_count, $rows['sent'] ?? 0, $campaign->title);
            $this->assertSame($campaign->failed_count, $rows['failed'] ?? 0, $campaign->title);
            $this->assertSame($campaign->skipped_count, $rows['skipped'] ?? 0, $campaign->title);
        }
    }

    public function test_history_covers_every_finished_status(): void
    {
        $statuses = Campaign::pluck('status')->map->value->unique()->values()->all();

        foreach (['completed', 'partially_failed', 'failed', 'cancelled'] as $status) {
            $this->assertContains($status, $statuses);
        }
        $this->assertTrue(Campaign::where('is_test', true)->exists());
    }

    public function test_marathi_templates_are_seeded_intact(): void
    {
        $template = MessageTemplate::firstWhere('title', 'आजच्या चालू घडामोडी');

        $this->assertNotNull($template);
        $this->assertStringContainsString('आजच्या महत्त्वाच्या चालू घडामोडी', $template->message);
        $this->assertSame('Text + PDF', $template->typeLabel());
    }

    public function test_media_files_exist_on_the_private_disk(): void
    {
        $this->assertSame(8, Media::count());

        foreach (Media::all() as $media) {
            Storage::disk('local')->assertExists($media->path);
            if ($media->thumbnail_path) {
                Storage::disk('local')->assertExists($media->thumbnail_path);
            }
        }
    }

    public function test_demo_history_is_not_duplicated_when_seeding_again(): void
    {
        $count = Campaign::count();

        $this->seed(DemoCampaignSeeder::class);

        $this->assertSame($count, Campaign::count());
    }
}

<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\GroupStatus;
use App\Enums\SendErrorType;
use App\Enums\WhatsAppConnectionStatus;
use App\Livewire\Groups\Index as GroupIndex;
use App\Models\Category;
use App\Models\Group;
use App\Models\Media;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Services\WhatsApp\PlaywrightWhatsAppService;
use App\Services\WhatsApp\WhatsAppServiceInterface;
use App\Services\WhatsApp\WorkerUnavailableException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The HTTP bridge to the local Playwright worker (docs/MASTER_PROMPT.md §32). The worker
 * itself is exercised separately with playwright/scripts/check-status.js; here the worker
 * is faked so the contract between Laravel and it is pinned down.
 */
class PlaywrightWorkerTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'http://127.0.0.1:3010';

    private const TOKEN = 'test-worker-token';

    private function service(): PlaywrightWhatsAppService
    {
        return new PlaywrightWhatsAppService(self::URL, self::TOKEN);
    }

    public function test_every_request_carries_the_worker_token(): void
    {
        Http::fake([self::URL.'/status' => Http::response(['state' => 'CONNECTED'])]);

        $this->service()->status();

        Http::assertSent(fn (Request $r) => $r->hasHeader('X-Worker-Token', self::TOKEN));
    }

    public function test_worker_states_map_to_connection_statuses(): void
    {
        $cases = [
            'CONNECTED' => WhatsAppConnectionStatus::Connected,
            'WAITING_FOR_QR' => WhatsAppConnectionStatus::WaitingForQr,
            'STARTING' => WhatsAppConnectionStatus::Starting,
            'DISCONNECTED' => WhatsAppConnectionStatus::Disconnected,
            // Anything unrecognised must read as disconnected rather than assumed working.
            'something-unexpected' => WhatsAppConnectionStatus::Disconnected,
        ];

        $sequence = Http::fakeSequence(self::URL.'/status');

        foreach (array_keys($cases) as $reported) {
            $sequence->push(['state' => $reported]);
        }

        foreach ($cases as $reported => $expected) {
            $this->assertSame($expected, $this->service()->status(), "state {$reported}");
        }
    }

    public function test_an_unreachable_worker_is_reported_as_unavailable(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->expectException(WorkerUnavailableException::class);

        $this->service()->status();
    }

    public function test_a_failing_worker_response_is_reported_as_unavailable(): void
    {
        Http::fake([self::URL.'/connect' => Http::response('nope', 500)]);

        $this->expectException(WorkerUnavailableException::class);

        $this->service()->connect();
    }

    public function test_a_successful_send_returns_a_sent_result(): void
    {
        Http::fake([self::URL.'/send' => Http::response(['success' => true, 'group' => 'Group 1'])]);

        $group = Group::factory()->create(['name' => 'Group 1']);
        $result = $this->service()->sendToGroup($group, 'hello');

        $this->assertTrue($result->success);
        $this->assertSame('Group 1', $result->group);
    }

    public function test_the_message_and_attachment_path_are_passed_to_the_worker(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('media/file.pdf', 'pdf');

        Http::fake([self::URL.'/send' => Http::response(['success' => true, 'group' => 'Group 1'])]);

        $group = Group::factory()->create(['name' => 'Group 1']);
        $media = Media::factory()->create(['path' => 'media/file.pdf']);

        $this->service()->sendToGroup($group, "मराठी\nline two", $media);

        Http::assertSent(function (Request $r) {
            return $r['group'] === 'Group 1'
                && $r['message'] === "मराठी\nline two"
                && str_ends_with($r['attachment_path'], 'media/file.pdf');
        });
    }

    public function test_worker_error_types_become_send_errors(): void
    {
        Http::fake([self::URL.'/send' => Http::response([
            'success' => false,
            'group' => 'Group 1',
            'error_type' => 'GROUP_NOT_FOUND',
            'error_message' => 'No WhatsApp group matches "Group 1" exactly.',
        ])]);

        $group = Group::factory()->create(['name' => 'Group 1']);
        $result = $this->service()->sendToGroup($group, 'hello');

        $this->assertFalse($result->success);
        $this->assertSame(SendErrorType::GroupNotFound, $result->errorType);
    }

    public function test_an_unreachable_worker_during_a_send_does_not_throw(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $group = Group::factory()->create(['name' => 'Group 1']);
        $result = $this->service()->sendToGroup($group, 'hello');

        // A send must never throw: the queue records it as a failure that pauses the campaign.
        $this->assertFalse($result->success);
        $this->assertSame(SendErrorType::WorkerUnavailable, $result->errorType);
        $this->assertTrue($result->errorType->pausesCampaign());
    }

    public function test_a_busy_worker_is_not_reported_as_a_successful_send(): void
    {
        Http::fake([self::URL.'/send' => Http::response([
            'success' => false, 'error_type' => 'UNKNOWN_ERROR', 'error_message' => 'Worker is busy sending another message.',
        ], 409)]);

        $group = Group::factory()->create(['name' => 'Group 1']);
        $result = $this->service()->sendToGroup($group, 'hello');

        $this->assertFalse($result->success);
    }

    public function test_sync_imports_only_new_chats_and_leaves_them_inactive(): void
    {
        config(['educationhub.whatsapp.driver' => 'playwright']);

        $category = Category::factory()->create();
        config(['educationhub.groups.default_category_id' => $category->id]);

        Group::factory()->create(['name' => 'Already Here']);

        Http::fake([self::URL.'/groups*' => Http::response([
            'success' => true,
            'groups' => ['Already Here', 'Group 1', 'Group 2'],
        ])]);

        $this->app->instance(WhatsAppServiceInterface::class, $this->service());
        $this->connectedSession();

        Livewire::actingAs(User::factory()->create())
            ->test(GroupIndex::class)
            ->call('syncFromWhatsApp');

        $this->assertSame(1, Group::where('name', 'Already Here')->count(), 'existing group must not be duplicated');

        foreach (['Group 1', 'Group 2'] as $name) {
            $group = Group::where('name', $name)->first();
            $this->assertNotNull($group, "{$name} should be imported");
            // Imported chats may be personal contacts, so they must not be sendable until reviewed.
            $this->assertSame(GroupStatus::Inactive, $group->status);
        }
    }

    /**
     * groups.name is utf8mb4_unicode_ci, so names that differ only by case are the same row
     * to MySQL. Comparing them in PHP let both through and the insert died with a unique
     * constraint violation, which surfaced as a 500 on the Sync button.
     */
    public function test_sync_treats_names_differing_only_by_case_as_the_same_chat(): void
    {
        config(['educationhub.whatsapp.driver' => 'playwright']);
        $category = Category::factory()->create();
        config(['educationhub.groups.default_category_id' => $category->id]);

        Http::fake([self::URL.'/groups*' => Http::response([
            'success' => true,
            // WhatsApp reporting the same chat twice under different casing.
            'groups' => ['Bajaj Direct Offers', 'Bajaj direct offers', 'BAJAJ DIRECT OFFERS'],
        ])]);

        $this->app->instance(WhatsAppServiceInterface::class, $this->service());
        $this->connectedSession();

        Livewire::actingAs(User::factory()->create())
            ->test(GroupIndex::class)
            ->call('syncFromWhatsApp');

        $this->assertSame(1, Group::count(), 'the three casings are one chat, so one group');
    }

    public function test_sync_reports_nothing_new_when_every_chat_is_already_known(): void
    {
        config(['educationhub.whatsapp.driver' => 'playwright']);
        $category = Category::factory()->create();
        config(['educationhub.groups.default_category_id' => $category->id]);

        Group::factory()->create(['name' => 'Group 1']);

        Http::fake([self::URL.'/groups*' => Http::response(['success' => true, 'groups' => ['Group 1']])]);

        $this->app->instance(WhatsAppServiceInterface::class, $this->service());
        $this->connectedSession();

        Livewire::actingAs(User::factory()->create())
            ->test(GroupIndex::class)
            ->call('syncFromWhatsApp')
            ->assertDispatched('toast', type: 'info');

        $this->assertSame(1, Group::count());
    }

    public function test_sync_settings_control_scope_status_and_category(): void
    {
        config(['educationhub.whatsapp.driver' => 'playwright']);

        $importCategory = Category::factory()->create(['name' => 'Imported']);
        $otherCategory = Category::factory()->create(['name' => 'Default']);

        config([
            'educationhub.groups.default_category_id' => $otherCategory->id,
            'educationhub.whatsapp.sync.scope' => 'all',
            'educationhub.whatsapp.sync.status' => 'active',
            'educationhub.whatsapp.sync.category_id' => $importCategory->id,
        ]);

        Http::fake([self::URL.'/groups*' => Http::response(['success' => true, 'groups' => ['A Chat']])]);

        $this->app->instance(WhatsAppServiceInterface::class, $this->service());
        $this->connectedSession();

        Livewire::actingAs(User::factory()->create())
            ->test(GroupIndex::class)
            ->call('syncFromWhatsApp');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'scope=all'));

        $group = Group::where('name', 'A Chat')->firstOrFail();
        $this->assertSame(GroupStatus::Active, $group->status);
        $this->assertSame($importCategory->id, $group->category_id, 'the sync category wins over the group default');
    }

    public function test_sync_skips_chats_named_as_a_phone_number(): void
    {
        config(['educationhub.whatsapp.driver' => 'playwright']);
        $category = Category::factory()->create();
        config([
            'educationhub.groups.default_category_id' => $category->id,
            'educationhub.whatsapp.sync.skip_phone_numbers' => true,
        ]);

        Http::fake([self::URL.'/groups*' => Http::response([
            'success' => true,
            'groups' => ['+91 84483 11302', '9876543210', 'MPSC Batch 01'],
        ])]);

        $this->app->instance(WhatsAppServiceInterface::class, $this->service());
        $this->connectedSession();

        Livewire::actingAs(User::factory()->create())
            ->test(GroupIndex::class)
            ->call('syncFromWhatsApp');

        $this->assertSame(['MPSC Batch 01'], Group::pluck('name')->all());
    }

    public function test_sync_is_refused_when_whatsapp_is_not_connected(): void
    {
        config(['educationhub.whatsapp.driver' => 'playwright']);
        $this->app->instance(WhatsAppServiceInterface::class, $this->service());

        Http::fake();

        Livewire::actingAs(User::factory()->create())
            ->test(GroupIndex::class)
            ->call('syncFromWhatsApp');

        Http::assertNothingSent();
        $this->assertSame(0, Group::count());
    }

    private function connectedSession(): void
    {
        WhatsAppSession::current()->forceFill([
            'status' => WhatsAppConnectionStatus::Connected,
            'last_seen_at' => now(),
        ])->save();
    }
}

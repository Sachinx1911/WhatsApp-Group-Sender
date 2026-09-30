<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\CampaignStatus;
use App\Enums\WhatsAppConnectionStatus as State;
use App\Livewire\Settings\Index;
use App\Models\AppNotification;
use App\Models\Campaign;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Services\WhatsApp\FakeWhatsAppService;
use App\Services\WhatsApp\WhatsAppServiceInterface;
use App\Services\WhatsApp\WhatsAppSessionManager;
use App\Services\WhatsApp\WorkerUnavailableException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class WhatsAppSessionTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-worker-token-0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['educationhub.whatsapp.worker.token' => self::TOKEN]);
    }

    private function manager(): WhatsAppSessionManager
    {
        return app(WhatsAppSessionManager::class);
    }

    private function report(array $body, ?string $token = self::TOKEN, string $ip = '127.0.0.1')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeaders(array_filter(['X-Worker-Token' => $token]))
            ->postJson('/internal/whatsapp/state', $body);
    }

    // ---- Internal state route ------------------------------------------------

    public function test_the_worker_reports_its_state(): void
    {
        $this->report(['state' => 'WAITING_FOR_QR'])->assertOk()->assertJson(['ok' => true, 'state' => 'waiting_for_qr']);
        $this->assertSame(State::WaitingForQr, WhatsAppSession::current()->status);

        $this->report(['state' => 'CONNECTED'])->assertOk();
        $session = WhatsAppSession::current();
        $this->assertTrue($session->isConnected());
        $this->assertNotNull($session->last_connected_at);
        $this->assertNotNull($session->last_seen_at);
    }

    public function test_the_state_route_needs_the_token_and_this_computer(): void
    {
        $this->report(['state' => 'CONNECTED'], token: null)->assertUnauthorized();
        $this->report(['state' => 'CONNECTED'], token: 'wrong')->assertUnauthorized();
        $this->report(['state' => 'CONNECTED'], ip: '192.168.1.20')->assertForbidden();

        config(['educationhub.whatsapp.worker.token' => null]); // no token configured: nothing gets in
        $this->report(['state' => 'CONNECTED'], token: '')->assertUnauthorized();

        $this->assertSame(State::Disconnected, WhatsAppSession::current()->status);
    }

    public function test_the_state_route_rejects_unknown_states_and_needs_no_csrf_or_login(): void
    {
        $this->report(['state' => 'HACKED'])->assertUnprocessable();

        // No session cookie, no CSRF token, not logged in — only the worker token.
        $this->report(['state' => 'connected'])->assertOk()->assertCookieMissing(config('session.cookie'));
    }

    // ---- Session manager -------------------------------------------------------

    public function test_losing_the_connection_pauses_the_sending_campaign_and_notifies(): void
    {
        $this->manager()->record(State::Connected, 'worker');
        $campaign = Campaign::factory()->create(['status' => CampaignStatus::Sending]);

        $this->report(['state' => 'DISCONNECTED', 'reason' => 'Logged out from phone'])->assertOk();

        $this->assertSame(CampaignStatus::Paused, $campaign->fresh()->status);
        $this->assertTrue(AppNotification::where('type', 'whatsapp_disconnected')->exists());
        $this->assertSame('Logged out from phone', WhatsAppSession::current()->metadata['reason']);
    }

    public function test_losing_the_connection_while_idle_only_notifies(): void
    {
        $this->manager()->record(State::Connected, 'worker');

        $this->manager()->record(State::Disconnected, 'worker');
        $this->manager()->record(State::Disconnected, 'worker'); // heartbeat with the same state: no second notice

        $this->assertSame(1, AppNotification::where('type', 'whatsapp_disconnected')->count());
    }

    public function test_worker_health(): void
    {
        $manager = $this->manager();
        config(['educationhub.whatsapp.driver' => 'playwright']);
        $this->assertFalse($manager->workerRunning());

        $manager->heartbeat();
        $this->assertTrue($manager->workerRunning());

        $this->travel(2)->minutes();
        $this->assertFalse($manager->workerRunning());

        config(['educationhub.whatsapp.driver' => 'fake']);
        $this->assertTrue($manager->workerRunning());
    }

    public function test_a_stalled_queue_is_detected(): void
    {
        config(['queue.default' => 'database']);
        $this->assertFalse($this->manager()->queueStalled());

        DB::table('jobs')->insert([
            'queue' => 'whatsapp', 'payload' => '{}', 'attempts' => 0,
            'available_at' => now()->subMinutes(5)->getTimestamp(), 'created_at' => now()->subMinutes(5)->getTimestamp(),
        ]);

        $this->assertTrue($this->manager()->queueStalled());
    }

    // ---- Settings screen ---------------------------------------------------------

    public function test_connect_and_disconnect_from_settings(): void
    {
        $this->actingAs(User::factory()->create());
        app(FakeWhatsAppService::class)->disconnect();

        Livewire::test(Index::class)
            ->assertSee('Connect WhatsApp')
            ->call('connectWhatsApp')
            ->assertDispatched('toast', type: 'success', message: 'WhatsApp connected')
            ->assertDispatched('whatsapp-status-changed')
            ->assertSee('Disconnect');

        $this->assertTrue(WhatsAppSession::current()->isConnected());
        $this->assertSame(State::Connected, app(WhatsAppServiceInterface::class)->status());

        $campaign = Campaign::factory()->create(['status' => CampaignStatus::Sending]);

        Livewire::test(Index::class)
            ->assertSee('is sending right now')
            ->call('disconnectWhatsApp')
            ->assertDispatched('toast', type: 'success', message: 'WhatsApp disconnected')
            ->assertSee('Connect WhatsApp');

        $this->assertSame(State::Disconnected, WhatsAppSession::current()->status);
        $this->assertSame(State::Disconnected, app(WhatsAppServiceInterface::class)->status());
        $this->assertSame(CampaignStatus::Paused, $campaign->fresh()->status);
        $this->assertSame(0, AppNotification::count()); // the admin did it: no alarm
    }

    public function test_refresh_reads_the_real_state(): void
    {
        $this->actingAs(User::factory()->create());
        $this->assertSame(State::Disconnected, WhatsAppSession::current()->status); // stale

        Livewire::test(Index::class)->call('refreshSession')->assertDispatched('toast', message: 'Status refreshed: Connected');

        $this->assertTrue(WhatsAppSession::current()->isConnected());
    }

    public function test_a_missing_worker_is_explained(): void
    {
        $this->actingAs(User::factory()->create());
        $down = Mockery::mock(WhatsAppServiceInterface::class);
        $down->shouldReceive('connect', 'status')->andThrow(WorkerUnavailableException::make());
        $this->app->instance(WhatsAppServiceInterface::class, $down);

        Livewire::test(Index::class)
            ->call('connectWhatsApp')
            ->assertDispatched('toast', type: 'error', message: WorkerUnavailableException::make()->getMessage())
            ->call('refreshSession')
            ->assertDispatched('toast', type: 'error');

        $this->assertSame(State::Disconnected, WhatsAppSession::current()->status);
    }

    public function test_the_header_badge_follows_the_session(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/')->assertSee('WhatsApp Disconnected');

        $this->report(['state' => 'WAITING_FOR_QR']);
        $this->get('/')->assertSee('WhatsApp Waiting for QR scan');
    }
}

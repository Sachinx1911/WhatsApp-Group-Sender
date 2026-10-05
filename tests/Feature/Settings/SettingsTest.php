<?php

namespace Tests\Feature\Settings;

use App\Actions\Groups\GroupCsv;
use App\Actions\Media\StoreUploadedMedia;
use App\Livewire\Groups\EditGroup;
use App\Livewire\Layout\NotificationBell;
use App\Livewire\Media\Picker;
use App\Livewire\SendMessage\Compose;
use App\Livewire\Settings\Index;
use App\Models\AppNotification;
use App\Models\Category;
use App\Models\Group;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->actingAs(User::factory()->create());
    }

    // ---- Settings helper ---------------------------------------------------

    public function test_set_stores_values_and_applies_them_to_config(): void
    {
        Settings::set([
            'educationhub.sending.daily_limit' => '250',
            'educationhub.message.footer' => '  _— Education Hub_  ',
            'educationhub.message.auto_add_footer' => '1',
            'not.a.setting' => 'ignored',
        ]);

        $this->assertSame(250, config('educationhub.sending.daily_limit'));
        $this->assertSame('_— Education Hub_', config('educationhub.message.footer'));
        $this->assertTrue(config('educationhub.message.auto_add_footer'));
        $this->assertSame(250, Setting::firstWhere('key', 'educationhub.sending.daily_limit')->value);
        $this->assertFalse(Setting::where('key', 'not.a.setting')->exists());
    }

    public function test_invalid_values_are_rejected(): void
    {
        $this->expectException(ValidationException::class);

        Settings::set(['educationhub.sending.delay_seconds' => 2]); // minimum is 3
    }

    public function test_reset_restores_the_defaults(): void
    {
        $default = config('educationhub.sending.max_groups_per_campaign');
        Settings::set(['educationhub.sending.max_groups_per_campaign' => 42]);

        Settings::reset(['educationhub.sending.max_groups_per_campaign']);

        $this->assertSame($default, config('educationhub.sending.max_groups_per_campaign'));
        $this->assertSame(0, Setting::count());
    }

    public function test_apply_only_touches_stored_keys(): void
    {
        config(['educationhub.sending.delay_seconds' => 0]); // like the test setup

        Settings::apply();

        $this->assertSame(0, config('educationhub.sending.delay_seconds'));
    }

    // ---- Settings screen ---------------------------------------------------

    public function test_every_section_renders(): void
    {
        foreach (array_keys(Index::SECTIONS) as $section) {
            $this->get(route('settings.index', ['section' => $section]))
                ->assertOk()
                ->assertSee(Index::SECTIONS[$section][0]);
        }
    }

    public function test_saving_a_section(): void
    {
        $group = Group::factory()->create(['name' => 'My Test Group']);

        Livewire::test(Index::class, ['section' => 'sending'])
            ->set('s.delay_seconds', 20)
            ->set('s.daily_limit', 300)
            ->set('s.test_group_id', (string) $group->id)
            ->set('s.show_progress', false)
            ->call('save', 'sending')
            ->assertHasNoErrors()
            ->assertDispatched('toast');

        $this->assertSame(20, config('educationhub.sending.delay_seconds'));
        $this->assertSame(300, config('educationhub.sending.daily_limit'));
        $this->assertTrue(Group::testGroup()->is($group));
        $this->assertFalse(config('educationhub.sending.show_progress'));
    }

    public function test_validation_errors_are_shown_and_nothing_is_saved(): void
    {
        Livewire::test(Index::class, ['section' => 'media'])
            ->set('s.max_image_mb', 50) // WhatsApp images: at most 16 MB
            ->call('save', 'media')
            ->assertHasErrors('s.max_image_mb');

        $this->assertSame(0, Setting::count());
    }

    public function test_restore_defaults_for_a_section(): void
    {
        Settings::set(['educationhub.media.max_pdf_mb' => 10]);

        Livewire::test(Index::class, ['section' => 'media'])
            ->assertSet('s.max_pdf_mb', 10)
            ->call('restoreDefaults', 'media')
            ->assertSet('s.max_pdf_mb', 100);
    }

    public function test_appearance_changes_apply_immediately(): void
    {
        Livewire::test(Index::class, ['section' => 'appearance'])
            ->set('s.compact_tables', true)
            ->call('save', 'appearance')
            ->assertDispatched('appearance-changed', compactTables: true, sidebarCollapsed: false);

        $this->get('/')->assertSee('<html lang="en" class="compact-tables">', false);
    }

    // ---- Settings used by the rest of the app -------------------------------

    public function test_send_message_uses_message_and_group_settings(): void
    {
        Group::factory()->create();

        Livewire::test(Compose::class)->assertSee('/ 4,096')->assertSee('Inactive');

        Settings::set([
            'educationhub.message.show_counter' => false,
            'educationhub.groups.show_inactive_in_selector' => false,
            'educationhub.message.default_type' => 'pdf',
        ]);

        Livewire::test(Compose::class)
            ->assertDontSee('/ 4,096')
            ->assertDontSee('>Inactive<', false)
            ->assertSee("type: 'pdf'", false);

        Livewire::test(Picker::class)->call('open', 'send-message', 'pdf')->assertSet('type', 'pdf');
    }

    public function test_remember_previous_group_selection(): void
    {
        Settings::set(['educationhub.groups.remember_selection' => true]);
        $groups = Group::factory()->count(2)->create();
        $ids = $groups->map(fn ($g) => (string) $g->id)->all();

        Livewire::test(Compose::class)
            ->set('form.message', 'Hello')
            ->set('form.groups', $ids)
            ->call('startSending');

        $this->assertSame($groups->pluck('id')->all(), config('educationhub.groups.last_selection'));
        Livewire::test(Compose::class)->assertSet('form.groups', $ids);
    }

    public function test_show_progress_off_goes_to_send_history(): void
    {
        Settings::set(['educationhub.sending.show_progress' => false]);
        $group = Group::factory()->create();

        Livewire::test(Compose::class)
            ->set('form.message', 'Hello')
            ->set('form.groups', [(string) $group->id])
            ->call('startSending')
            ->assertRedirect(route('history.index'));
    }

    public function test_default_category_for_new_groups_and_csv(): void
    {
        Category::factory()->create(['name' => 'Other']);
        $mpsc = Category::factory()->create(['name' => 'MPSC']);

        Livewire::test(EditGroup::class)->call('create')->assertSet('form.category_id', Category::firstWhere('name', 'Other')->id);

        Settings::set(['educationhub.groups.default_category_id' => $mpsc->id]);

        Livewire::test(EditGroup::class)->call('create')->assertSet('form.category_id', $mpsc->id);

        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, "name,category\nNew Batch,");
        $this->assertSame('MPSC', app(GroupCsv::class)->parse($path)[0]['category']);
    }

    public function test_group_search_mode(): void
    {
        Group::factory()->create(['name' => 'MPSC Batch 01']);
        Group::factory()->create(['name' => 'Evening MPSC']);

        $this->assertSame(2, Group::search('MPSC')->count());

        Settings::set(['educationhub.groups.search_mode' => 'starts_with']);

        $this->assertSame(['MPSC Batch 01'], Group::search('MPSC')->pluck('name')->all());
    }

    public function test_thumbnails_can_be_turned_off(): void
    {
        Storage::fake('local');
        Settings::set(['educationhub.media.generate_thumbnails' => false]);

        $media = app(StoreUploadedMedia::class)->handle(UploadedFile::fake()->image('poster.jpg', 400, 400));

        $this->assertNull($media->thumbnail_path);
        $this->get(route('media.thumbnail', $media))->assertOk(); // falls back to the full image
    }

    public function test_desktop_notifications_follow_the_settings(): void
    {
        $bell = Livewire::test(NotificationBell::class);
        AppNotification::create(['type' => 'campaign_completed', 'title' => 'Campaign completed']);

        $bell->call('checkForNew')->assertNotDispatched('desktop-notify'); // desktop notifications are off by default

        Settings::set(['educationhub.notifications.desktop' => true, 'educationhub.notifications.campaign_completed' => false]);
        AppNotification::create(['type' => 'campaign_completed', 'title' => 'Another one']);
        AppNotification::create(['type' => 'whatsapp_disconnected', 'title' => 'Sending paused']);

        $bell->call('checkForNew')
            ->assertDispatched('desktop-notify', title: 'Sending paused')
            ->assertNotDispatched('desktop-notify', title: 'Another one');

        $bell->call('checkForNew')->assertNotDispatched('desktop-notify', title: 'Never shown twice');
    }
}

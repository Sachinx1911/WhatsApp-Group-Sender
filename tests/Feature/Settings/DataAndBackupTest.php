<?php

namespace Tests\Feature\Settings;

use App\Actions\Data\ExportAllData;
use App\Enums\CampaignStatus;
use App\Livewire\Settings\Index;
use App\Models\Campaign;
use App\Models\Category;
use App\Models\Group;
use App\Models\Media;
use App\Models\MessageTemplate;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

class DataAndBackupTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        $this->admin = User::factory()->create(['password' => 'secret-password']);
        $this->actingAs($this->admin);
    }

    private function openZip(string $path): ZipArchive
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($path)));

        return $zip;
    }

    public function test_export_contains_all_data_and_media_but_no_passwords(): void
    {
        Group::factory()->create(['name' => 'मराठी बॅच 01']);
        $media = Media::factory()->pdf()->create();
        Storage::disk('local')->put($media->path, '%PDF-1.4');

        $path = app(ExportAllData::class)->handle();

        $zip = $this->openZip($path);
        $data = json_decode($zip->getFromName('data.json'), true);
        $this->assertSame('मराठी बॅच 01', $data['tables']['groups'][0]['name']);
        $this->assertArrayNotHasKey('users', $data['tables']);
        $this->assertStringNotContainsString($this->admin->password, $zip->getFromName('data.json'));
        $this->assertSame('%PDF-1.4', $zip->getFromName($media->path));
        $zip->close();
    }

    public function test_backups_are_listed_downloaded_and_deleted_from_the_screen(): void
    {
        Livewire::test(Index::class, ['section' => 'data'])
            ->call('exportNow')
            ->assertDispatched('toast');

        $name = ExportAllData::list()[0]['name'];
        $this->assertTrue(ExportAllData::isValidName($name));

        $this->get(route('backups.download', $name))->assertOk()->assertDownload($name);

        Livewire::test(Index::class, ['section' => 'data'])->assertSee($name)->call('deleteBackup', $name);
        $this->assertSame([], ExportAllData::list());
    }

    public function test_backup_download_only_accepts_backup_names(): void
    {
        Storage::disk('local')->put('media/secret.pdf', 'x');

        $this->get('/settings/backups/..%2Fmedia%2Fsecret.pdf')->assertNotFound();
        $this->get(route('backups.download', 'secret.pdf'))->assertNotFound();

        auth()->logout();
        $this->get(route('backups.download', 'education-hub-export-2026-09-30-120000.zip'))->assertRedirect('/login');
    }

    public function test_clear_temporary_files_removes_only_old_unfinished_uploads(): void
    {
        $disk = Storage::disk('local');
        $disk->put('livewire-tmp/old.png', str_repeat('x', 100));
        $disk->put('livewire-tmp/new.png', 'x');
        $disk->put('media/keep.pdf', 'x');
        touch($disk->path('livewire-tmp/old.png'), now()->subDays(3)->getTimestamp());

        Livewire::test(Index::class, ['section' => 'data'])->call('clearTemporaryFiles')->assertDispatched('toast');

        $disk->assertMissing('livewire-tmp/old.png');
        $disk->assertExists(['livewire-tmp/new.png', 'media/keep.pdf']);
    }

    public function test_reset_needs_the_typed_word(): void
    {
        Group::factory()->create();

        Livewire::test(Index::class, ['section' => 'data'])
            ->set('resetConfirmation', 'reset')
            ->call('resetApplication')
            ->assertHasErrors('resetConfirmation');

        $this->assertSame(1, Group::count());
    }

    public function test_reset_backs_up_then_clears_everything_except_the_login(): void
    {
        $this->seed(CategorySeeder::class);
        Category::factory()->create(['name' => 'Talathi Bharti']);
        Group::factory()->count(3)->create();
        MessageTemplate::factory()->create();
        $media = Media::factory()->create();
        Storage::disk('local')->put($media->path, 'png');
        Campaign::factory()->create(['status' => CampaignStatus::Completed]);
        Setting::create(['key' => 'educationhub.sending.daily_limit', 'value' => 10]);

        Livewire::test(Index::class, ['section' => 'data'])
            ->set('resetConfirmation', 'RESET')
            ->call('resetApplication')
            ->assertHasNoErrors()
            ->assertDispatched('toast', type: 'success');

        $this->assertSame([0, 0, 0, 0, 0], [Group::count(), MessageTemplate::count(), Media::count(), Campaign::count(), Setting::count()]);
        $this->assertSame(array_keys(CategorySeeder::DEFAULTS), Category::ordered()->pluck('name')->all());
        $this->assertModelExists($this->admin);
        Storage::disk('local')->assertMissing($media->path);

        $backup = ExportAllData::list()[0]['name'];
        $this->assertStringStartsWith('education-hub-before-reset-', $backup);
        $this->get(route('backups.download', $backup))->assertOk()->assertDownload($backup);
        $data = json_decode($this->openZip(ExportAllData::DIRECTORY.'/'.$backup)->getFromName('data.json'), true);
        $this->assertCount(3, $data['tables']['groups']);
    }

    public function test_reset_is_refused_while_a_campaign_is_sending(): void
    {
        Group::factory()->create();
        Campaign::factory()->create(['status' => CampaignStatus::Sending]);

        Livewire::test(Index::class, ['section' => 'data'])
            ->set('resetConfirmation', 'RESET')
            ->call('resetApplication')
            ->assertDispatched('toast', type: 'error');

        $this->assertSame(1, Group::count());
        $this->assertSame([], ExportAllData::list());
    }
}

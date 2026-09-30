<?php

namespace Tests\Feature\Media;

use App\Enums\CampaignStatus;
use App\Livewire\Media\Index;
use App\Models\Campaign;
use App\Models\Media;
use App\Models\MessageTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class MediaLibraryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
    }

    public function test_page_renders_items_and_stats(): void
    {
        Media::factory()->create(['original_name' => 'poster.png', 'usage_count' => 4]);
        Media::factory()->pdf()->create(['original_name' => 'notes.pdf']);

        $this->get('/media')
            ->assertOk()
            ->assertSeeLivewire(Index::class)
            ->assertSee('Manage your educational images and PDF files')
            ->assertSee('poster.png')
            ->assertSee('notes.pdf')
            ->assertSee('Used 4×')
            ->assertSee(route('send.create', ['media' => Media::first()->id]), false);

        $this->assertSame(['total' => 2, 'images' => 1, 'pdfs' => 1], Livewire::test(Index::class)->instance()->stats);
    }

    public function test_empty_state(): void
    {
        Livewire::test(Index::class)->assertSee('No media yet');
    }

    public function test_filters_sort_and_layout(): void
    {
        Media::factory()->create(['original_name' => 'b-poster.png', 'usage_count' => 1, 'size' => 100]);
        Media::factory()->pdf()->create(['original_name' => 'a-notes.pdf', 'usage_count' => 9, 'size' => 999]);

        Livewire::test(Index::class)
            ->set('type', 'pdf')->assertSee('a-notes.pdf')->assertDontSee('b-poster.png')
            ->set('type', 'image')->assertSee('b-poster.png')->assertDontSee('a-notes.pdf')
            ->set('type', '')->set('search', 'poster')->assertSee('b-poster.png')->assertDontSee('a-notes.pdf')
            ->set('search', '')
            ->set('sort', 'popular')->assertSeeInOrder(['a-notes.pdf', 'b-poster.png'])
            ->set('sort', 'name')->assertSeeInOrder(['a-notes.pdf', 'b-poster.png'])
            ->set('view', 'list')->assertSee('Uploaded')->assertSee('Actions');
    }

    public function test_preview_shows_details(): void
    {
        $media = Media::factory()->pdf()->create(['original_name' => 'notes.pdf']);
        MessageTemplate::factory()->create(['attachment_id' => $media->id]);

        Livewire::test(Index::class)
            ->call('preview', $media->id)
            ->assertDispatched('open-modal')
            ->assertSee(route('media.file', $media), false)
            ->assertSee('Default for templates');

        $this->assertSame(1, Livewire::test(Index::class)->call('preview', $media->id)->instance()->active->templates_count);
    }

    public function test_rename_keeps_the_extension_and_rejects_unsafe_names(): void
    {
        $media = Media::factory()->create(['original_name' => 'IMG_2031.png']);

        Livewire::test(Index::class)
            ->call('startRename', $media->id)
            ->assertSet('renameBase', 'IMG_2031')
            ->set('renameBase', '../etc/passwd')
            ->call('rename')
            ->assertHasErrors('renameBase')
            ->set('renameBase', ' साप्ताहिक निकाल ')
            ->call('rename')
            ->assertHasNoErrors();

        $this->assertSame('साप्ताहिक निकाल.png', $media->fresh()->original_name);
    }

    public function test_delete_removes_the_record_and_files(): void
    {
        $media = Media::factory()->create(['thumbnail_path' => 'media/thumbnails/t.png']);
        Storage::disk('local')->put($media->path, 'x');
        Storage::disk('local')->put($media->thumbnail_path, 't');
        $template = MessageTemplate::factory()->create(['attachment_id' => $media->id]);

        Livewire::test(Index::class)
            ->call('confirmDelete', $media->id)
            ->assertSet('activeName', $media->original_name)
            ->call('delete')
            ->assertDispatched('toast', type: 'success');

        $this->assertModelMissing($media);
        Storage::disk('local')->assertMissing([$media->path, $media->thumbnail_path]);
        $this->assertNull($template->fresh()->attachment_id);
    }

    public function test_files_needed_by_an_unfinished_campaign_cannot_be_deleted(): void
    {
        $media = Media::factory()->pdf()->create();
        Storage::disk('local')->put($media->path, '%PDF');
        Campaign::factory()->create(['attachment_id' => $media->id, 'status' => CampaignStatus::Sending]);

        Livewire::test(Index::class)
            ->call('confirmDelete', $media->id)
            ->call('delete')
            ->assertDispatched('toast', type: 'error');

        $this->assertModelExists($media);
        Storage::disk('local')->assertExists($media->path);
    }

    public function test_files_of_finished_campaigns_can_be_deleted_and_history_keeps_the_name(): void
    {
        $media = Media::factory()->pdf()->create(['original_name' => 'CA.pdf']);
        $campaign = Campaign::factory()->create(['attachment_id' => $media->id, 'attachment_name' => 'CA.pdf', 'status' => CampaignStatus::Completed]);

        Livewire::test(Index::class)->call('confirmDelete', $media->id)->call('delete');

        $this->assertModelMissing($media);
        $this->assertSame('CA.pdf', $campaign->fresh()->attachment_name);
    }
}

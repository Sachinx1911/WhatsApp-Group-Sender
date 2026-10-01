<?php

namespace Tests\Feature\Attachments;

use App\Actions\Campaigns\CreateCampaign;
use App\Livewire\SendMessage\Compose;
use App\Livewire\Templates\EditTemplate;
use App\Models\Campaign;
use App\Models\Category;
use App\Models\Group;
use App\Models\Media;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\WhatsApp\FakeWhatsAppService;
use App\Services\WhatsApp\WhatsAppServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Templates, the composer and a campaign can carry several images and PDFs. */
class MultipleAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::factory()->create();
        $this->actingAs(User::factory()->create());
    }

    public function test_a_campaign_keeps_every_attachment_in_order(): void
    {
        $group = Group::factory()->for($this->category)->create();
        $files = Media::factory()->count(3)->create();

        $campaign = app(CreateCampaign::class)->handle(
            message: 'three files',
            attachment: $files,
            groupIds: [$group->id],
        );

        $this->assertSame(
            $files->pluck('id')->all(),
            $campaign->attachments()->pluck('media.id')->all(),
            'order is preserved',
        );

        // The single column still points at the first file, so older screens keep working.
        $this->assertSame($files->first()->id, $campaign->attachment_id);
        $this->assertSame($files->first()->original_name, $campaign->attachment_name);
    }

    public function test_one_media_object_still_works(): void
    {
        $group = Group::factory()->for($this->category)->create();
        $file = Media::factory()->create();

        $campaign = app(CreateCampaign::class)->handle('one file', $file, [$group->id]);

        $this->assertSame([$file->id], $campaign->attachments()->pluck('media.id')->all());
        $this->assertSame($file->id, $campaign->attachment_id);
    }

    public function test_attachment_names_survive_the_media_being_deleted(): void
    {
        $group = Group::factory()->for($this->category)->create();
        $file = Media::factory()->create(['original_name' => 'notes.pdf']);

        $campaign = app(CreateCampaign::class)->handle('x', [$file], [$group->id]);

        $file->delete();

        // History must still say what went out, even once the library entry is gone.
        $this->assertSame(['notes.pdf'], $campaign->fresh()->attachmentNames());
    }

    public function test_every_attachment_is_handed_to_the_sender(): void
    {
        $fake = new FakeWhatsAppService;
        $this->app->instance(WhatsAppServiceInterface::class, $fake);

        $group = Group::factory()->for($this->category)->create();
        $files = Media::factory()->count(2)->create();

        app(CreateCampaign::class)->handle('two files', $files, [$group->id]);

        $this->assertCount(1, $fake->sent, 'one send per group');
        $this->assertSame($files->pluck('original_name')->all(), $fake->sent[0]['attachments']);
    }

    public function test_the_composer_collects_and_drops_files(): void
    {
        $files = Media::factory()->count(3)->create();

        $component = Livewire::test(Compose::class);

        foreach ($files as $file) {
            $component->call('attachFromLibrary', $file->id, Compose::PICKER_CONTEXT);
        }

        $component->assertSet('form.attachment_ids', $files->pluck('id')->all());

        $component->call('removeAttachment', $files[1]->id)
            ->assertSet('form.attachment_ids', [$files[0]->id, $files[2]->id])
            // attachment_id keeps tracking the first remaining file.
            ->assertSet('form.attachment_id', $files[0]->id);
    }

    public function test_the_composer_will_not_take_the_same_file_twice(): void
    {
        $file = Media::factory()->create();

        Livewire::test(Compose::class)
            ->call('attachFromLibrary', $file->id, Compose::PICKER_CONTEXT)
            ->call('attachFromLibrary', $file->id, Compose::PICKER_CONTEXT)
            ->assertSet('form.attachment_ids', [$file->id]);
    }

    public function test_a_template_saves_and_reloads_several_attachments(): void
    {
        $files = Media::factory()->count(2)->create();

        $component = Livewire::test(EditTemplate::class)
            ->call('create')
            ->set('form.title', 'Daily notes')
            ->set('form.message', 'Today’s notes');

        foreach ($files as $file) {
            $component->call('attach', $file->id, EditTemplate::PICKER_CONTEXT);
        }

        $component->call('save');

        $template = MessageTemplate::where('title', 'Daily notes')->firstOrFail();
        $this->assertSame($files->pluck('id')->all(), $template->attachments()->pluck('media.id')->all());

        // Reopening shows them all again.
        Livewire::test(EditTemplate::class)
            ->call('edit', $template->id)
            ->assertSet('form.attachment_ids', $files->pluck('id')->all());
    }

    public function test_using_a_template_brings_all_its_files_into_the_composer(): void
    {
        $files = Media::factory()->count(2)->create();
        $template = MessageTemplate::factory()->create(['message' => 'from template']);
        $template->attachments()->sync([
            $files[0]->id => ['position' => 0],
            $files[1]->id => ['position' => 1],
        ]);

        Livewire::test(Compose::class)
            ->call('applyTemplate', $template->id)
            ->assertSet('form.attachment_ids', $files->pluck('id')->all());
    }

    /** Templates saved before the pivot existed only have the single column. */
    public function test_a_template_with_only_the_old_column_still_attaches(): void
    {
        $file = Media::factory()->create();
        $template = MessageTemplate::factory()->create(['attachment_id' => $file->id]);

        Livewire::test(Compose::class)
            ->call('applyTemplate', $template->id)
            ->assertSet('form.attachment_ids', [$file->id]);
    }

    public function test_send_again_carries_every_file_from_the_old_campaign(): void
    {
        $group = Group::factory()->for($this->category)->create();
        $files = Media::factory()->count(2)->create();

        $campaign = app(CreateCampaign::class)->handle('again', $files, [$group->id]);

        Livewire::withQueryParams(['campaign' => $campaign->id])
            ->test(Compose::class)
            ->assertSet('form.attachment_ids', $files->pluck('id')->all());
    }

    /** The admin has to be able to see what they attached before sending it. */
    public function test_the_preview_and_review_name_every_attached_file(): void
    {
        $group = Group::factory()->for($this->category)->create();
        $pdfs = collect([
            Media::factory()->create(['original_name' => 'Paper_One.pdf', 'type' => 'pdf', 'mime_type' => 'application/pdf']),
            Media::factory()->create(['original_name' => 'Paper_Two.pdf', 'type' => 'pdf', 'mime_type' => 'application/pdf']),
        ]);

        Livewire::test(Compose::class)
            ->set('form.message', 'two papers')
            ->set('form.attachment_ids', $pdfs->pluck('id')->all())
            ->set('form.groups', [(string) $group->id])
            // Listed in the attachments card and the WhatsApp-style preview.
            ->assertSee('Paper_One.pdf')
            ->assertSee('Paper_Two.pdf')
            ->call('review')
            ->assertHasNoErrors()
            // Still both named once the review dialog is open.
            ->assertSee('Paper_One.pdf')
            ->assertSee('Paper_Two.pdf');
    }

    public function test_more_than_the_limit_is_refused(): void
    {
        $group = Group::factory()->for($this->category)->create();
        $files = Media::factory()->count(11)->create();

        Livewire::test(Compose::class)
            ->set('form.message', 'too many')
            ->set('form.attachment_ids', $files->pluck('id')->all())
            ->set('form.groups', [(string) $group->id])
            ->call('review')
            ->assertHasErrors('form.attachment_ids');

        $this->assertSame(0, Campaign::count());
    }
}

<?php

namespace Tests\Feature\SendMessage;

use App\Livewire\SendMessage\Compose;
use App\Livewire\Templates\Picker as TemplatePicker;
use App\Models\Campaign;
use App\Models\Category;
use App\Models\Group;
use App\Models\Media;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Support\SendEstimate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ComposeTest extends TestCase
{
    use RefreshDatabase;

    private Category $mpsc;

    private Category $police;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $this->mpsc = Category::factory()->create(['name' => 'MPSC', 'sort_order' => 0]);
        $this->police = Category::factory()->create(['name' => 'Police Bharti', 'sort_order' => 1]);
    }

    private function ids(iterable $groups): array
    {
        return collect($groups)->map(fn (Group $g) => (string) $g->id)->all();
    }

    public function test_page_renders(): void
    {
        Group::factory()->for($this->mpsc)->create(['name' => 'MPSC Batch 01', 'member_count' => 250]);

        $this->get('/send')
            ->assertOk()
            ->assertSeeLivewire(Compose::class)
            ->assertSee('Create and distribute educational content to selected WhatsApp groups')
            ->assertSee('Write your message here...')
            ->assertSee('Drag & drop image or PDF here', false)
            ->assertSee('MPSC Batch 01')
            ->assertSee('250 members')
            ->assertSee('Send Test')
            ->assertSee('Review & Send', false);
    }

    public function test_prefill_from_template_media_and_groups(): void
    {
        $pdf = Media::factory()->pdf()->create();
        $template = MessageTemplate::factory()->create(['message' => '*आजच्या चालू घडामोडी*', 'attachment_id' => $pdf->id]);
        $active = Group::factory()->for($this->mpsc)->create();
        $inactive = Group::factory()->for($this->mpsc)->inactive()->create();

        Livewire::withQueryParams(['template' => $template->id, 'groups' => [$active->id, $inactive->id, 999]])
            ->test(Compose::class)
            ->assertSet('form.message', '*आजच्या चालू घडामोडी*')
            ->assertSet('form.attachment_id', $pdf->id)
            ->assertSet('form.template_id', $template->id)
            ->assertSet('form.groups', [(string) $active->id]);

        $image = Media::factory()->create();
        Livewire::withQueryParams(['media' => $image->id])->test(Compose::class)->assertSet('form.attachment_id', $image->id);
    }

    public function test_inserting_a_template_without_attachment_keeps_the_current_attachment(): void
    {
        $image = Media::factory()->create();
        $template = MessageTemplate::factory()->create(['message' => 'सुट्टी सूचना', 'attachment_id' => null]);

        Livewire::test(Compose::class)
            ->set('form.attachment_id', $image->id)
            ->dispatch('template-picked', id: $template->id)
            ->assertSet('form.message', 'सुट्टी सूचना')
            ->assertSet('form.attachment_id', $image->id)
            ->assertDispatched('toast')
            ->call('clearMessage')
            ->assertSet('form.message', '')
            ->assertSet('form.template_id', null);
    }

    public function test_uploading_an_attachment_stores_it_in_the_media_library(): void
    {
        $component = Livewire::test(Compose::class)
            ->set('upload', UploadedFile::fake()->image('mcq.jpg', 800, 600))
            ->assertHasNoErrors();

        $media = Media::sole();
        $component->assertSet('form.attachment_id', $media->id)->assertSet('upload', null);

        Livewire::test(Compose::class)
            ->set('upload', UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'))
            ->assertHasErrors('upload')
            ->assertSet('form.attachment_id', null);
    }

    public function test_media_picked_only_applies_to_this_screen(): void
    {
        $media = Media::factory()->create();

        Livewire::test(Compose::class)
            ->dispatch('media-picked', id: $media->id, context: 'template-form')
            ->assertSet('form.attachment_id', null)
            ->dispatch('media-picked', id: $media->id, context: Compose::PICKER_CONTEXT)
            ->assertSet('form.attachment_id', $media->id)
            ->call('removeAttachment')
            ->assertSet('form.attachment_id', null);
    }

    public function test_group_list_filters(): void
    {
        Group::factory()->for($this->mpsc)->create(['name' => 'MPSC Batch 01']);
        Group::factory()->for($this->police)->create(['name' => 'Police Batch 01']);
        Group::factory()->for($this->police)->inactive()->create(['name' => 'Police Batch 09']);

        Livewire::test(Compose::class)
            ->assertSee('MPSC Batch 01')->assertDontSee('Police Batch 09')
            ->set('groupSearch', 'police')->assertSee('Police Batch 01')->assertDontSee('MPSC Batch 01')
            ->set('groupSearch', '')->set('groupCategory', (string) $this->mpsc->id)->assertSee('MPSC Batch 01')->assertDontSee('Police Batch 01')
            ->set('groupCategory', '')->set('groupView', 'inactive')->assertSee('Police Batch 09')->assertDontSee('MPSC Batch 01');
    }

    public function test_select_all_selects_every_matching_active_group_across_pages(): void
    {
        $mpsc = Group::factory()->for($this->mpsc)->count(30)->create();
        Group::factory()->for($this->mpsc)->inactive()->create();
        $police = Group::factory()->for($this->police)->create();

        $component = Livewire::test(Compose::class)
            ->set('form.groups', [(string) $police->id])
            ->set('groupCategory', (string) $this->mpsc->id)
            ->call('selectAllMatching');

        $this->assertEqualsCanonicalizing([...$this->ids($mpsc), (string) $police->id], $component->get('form.groups'));
        $this->assertSame(31, $component->instance()->selection['count']);

        $component->set('groupView', 'selected')->call('clearSelection')
            ->assertSet('form.groups', [])
            ->assertSet('groupView', 'active');
    }

    public function test_selection_summary(): void
    {
        $a = Group::factory()->for($this->mpsc)->create(['member_count' => 200]);
        $b = Group::factory()->for($this->mpsc)->create(['member_count' => 100]);
        $c = Group::factory()->for($this->police)->create(['member_count' => null]);

        $selection = Livewire::test(Compose::class)->set('form.groups', $this->ids([$a, $b, $c]))->instance()->selection;

        $this->assertSame(['count' => 3, 'members' => 300, 'categories' => ['MPSC' => 2, 'Police Bharti' => 1]], $selection);
    }

    public function test_review_requires_content_and_groups(): void
    {
        $group = Group::factory()->for($this->mpsc)->create();

        Livewire::test(Compose::class)
            ->call('review')
            ->assertHasErrors(['form.message' => 'required_without', 'form.groups' => 'required'])
            ->assertNotDispatched('open-modal');

        // An attachment alone is enough (WhatsApp allows media without a caption).
        Livewire::test(Compose::class)
            ->set('form.attachment_id', Media::factory()->create()->id)
            ->set('form.groups', [(string) $group->id])
            ->call('review')
            ->assertHasNoErrors()
            ->assertDispatched('open-modal');
    }

    public function test_review_rejects_inactive_groups_and_too_many_groups(): void
    {
        config(['educationhub.sending.max_groups_per_campaign' => 2]);
        $inactive = Group::factory()->for($this->mpsc)->inactive()->create();
        $active = Group::factory()->for($this->mpsc)->count(3)->create();

        Livewire::test(Compose::class)
            ->set('form.message', 'नमस्कार')
            ->set('form.groups', [(string) $inactive->id])
            ->call('review')
            ->assertHasErrors('form.groups.0')
            ->set('form.groups', $this->ids($active))
            ->call('review')
            ->assertHasErrors(['form.groups' => 'max']);
    }

    public function test_large_selection_needs_the_typed_count_and_nothing_is_sent_yet(): void
    {
        config(['educationhub.sending.large_selection_threshold' => 2]);
        $groups = Group::factory()->for($this->mpsc)->count(3)->create();

        Livewire::test(Compose::class)
            ->set('form.message', 'आजच्या चालू घडामोडी')
            ->set('form.groups', $this->ids($groups))
            ->call('review')
            ->assertSee('This is a large send')
            ->call('startSending')
            ->assertHasErrors('form.confirmCount')
            ->set('form.confirmCount', '2')
            ->call('startSending')
            ->assertHasErrors('form.confirmCount')
            ->set('form.confirmCount', ' 3 ')
            ->call('startSending')
            ->assertHasNoErrors()
            ->assertDispatched('toast', type: 'info');

        // The campaign engine arrives in Phase 9.
        $this->assertSame(0, Campaign::count());
    }

    public function test_small_selection_needs_no_typed_confirmation(): void
    {
        $group = Group::factory()->for($this->mpsc)->create();

        Livewire::test(Compose::class)
            ->set('form.message', 'Hello')
            ->set('form.groups', [(string) $group->id])
            ->call('startSending')
            ->assertHasNoErrors();
    }

    public function test_send_test_uses_the_test_group(): void
    {
        Livewire::test(Compose::class)
            ->set('form.message', 'Test')
            ->call('sendTest')
            ->assertDispatched('toast', type: 'error')
            ->assertNotDispatched('open-modal');

        Group::factory()->for($this->mpsc)->create(['name' => 'Education Hub Test Group']);

        Livewire::test(Compose::class)
            ->call('sendTest')
            ->assertHasErrors('form.message')
            ->set('form.message', 'Test')
            ->call('sendTest')
            ->assertDispatched('open-modal')
            ->assertSee('Education Hub Test Group');

        $configured = Group::factory()->for($this->mpsc)->create(['name' => 'My Own Test']);
        config(['educationhub.sending.test_group_id' => $configured->id]);
        $this->assertTrue(Group::testGroup()->is($configured));
    }

    public function test_auto_footer_is_added_to_the_final_message(): void
    {
        config(['educationhub.message.footer' => '_— Education Hub_', 'educationhub.message.auto_add_footer' => true]);

        $component = Livewire::test(Compose::class)->set('form.message', "नमस्कार\n");

        $this->assertSame("नमस्कार\n\n_— Education Hub_", $component->instance()->form->finalMessage());
    }

    public function test_estimate(): void
    {
        config(['educationhub.sending.delay_seconds' => 15, 'educationhub.sending.average_send_seconds' => 8]);

        $this->assertSame('< 1 min', SendEstimate::label(2));
        $this->assertSame('≈ 4 min', SendEstimate::label(9));
        $this->assertSame('≈ 1 h 36 min', SendEstimate::label(250));
        $this->assertSame('≈ 2 h', SendEstimate::label(313));
    }

    public function test_template_picker(): void
    {
        $template = MessageTemplate::factory()->create(['title' => 'Daily MCQ', 'message' => 'दैनिक सराव']);
        MessageTemplate::factory()->create(['title' => 'Holiday']);

        Livewire::test(TemplatePicker::class)
            ->call('open')
            ->assertDispatched('open-modal')
            ->set('search', 'सराव')
            ->assertSee('Daily MCQ')->assertDontSee('Holiday')
            ->call('choose', $template->id)
            ->assertDispatched('template-picked', id: $template->id);
    }
}

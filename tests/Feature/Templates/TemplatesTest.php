<?php

namespace Tests\Feature\Templates;

use App\Livewire\Forms\TemplateForm;
use App\Livewire\Media\Picker;
use App\Livewire\Templates\EditTemplate;
use App\Livewire\Templates\Index;
use App\Models\Category;
use App\Models\Media;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Support\WhatsAppFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TemplatesTest extends TestCase
{
    use RefreshDatabase;

    private Category $mpsc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->actingAs(User::factory()->create());
        $this->mpsc = Category::factory()->create(['name' => 'MPSC']);
    }

    public function test_page_lists_templates_with_formatted_preview(): void
    {
        MessageTemplate::factory()->create([
            'title' => 'आजच्या चालू घडामोडी',
            'message' => "*📰 आजच्या चालू घडामोडी*\nनक्की वाचा",
            'category_id' => $this->mpsc->id,
            'tags' => ['current-affairs'],
            'attachment_id' => Media::factory()->pdf()->create(['original_name' => 'CA_30_09.pdf'])->id,
        ]);

        $this->get('/templates')
            ->assertOk()
            ->assertSeeLivewire(Index::class)
            ->assertSee('Save frequently used educational messages')
            ->assertSee('<strong>📰 आजच्या चालू घडामोडी</strong>', false)
            ->assertSee('Text + PDF')
            ->assertSee('#current-affairs')
            ->assertSee('CA_30_09.pdf')
            ->assertSee(route('send.create', ['template' => MessageTemplate::first()->id]), false);
    }

    public function test_empty_state(): void
    {
        Livewire::test(Index::class)->assertSee('No templates yet');
    }

    public function test_filters_by_search_tag_category_and_type(): void
    {
        MessageTemplate::factory()->create(['title' => 'Daily MCQ', 'message' => 'दैनिक सराव', 'tags' => ['daily-mcq'],
            'attachment_id' => Media::factory()->create()->id, 'category_id' => $this->mpsc->id]);
        MessageTemplate::factory()->create(['title' => 'Holiday notice', 'message' => 'सुट्टी', 'tags' => ['notice']]);
        MessageTemplate::factory()->create(['title' => 'Current affairs', 'message' => 'चालू घडामोडी',
            'attachment_id' => Media::factory()->pdf()->create()->id]);

        Livewire::test(Index::class)
            ->set('search', 'सुट्टी')->assertSee('Holiday notice')->assertDontSee('Daily MCQ')
            ->set('search', '#daily-mcq')->assertSee('Daily MCQ')->assertDontSee('Holiday notice')
            ->set('search', '')->set('category', (string) $this->mpsc->id)->assertSee('Daily MCQ')->assertDontSee('Current affairs')
            ->call('clearFilters')
            ->set('type', 'text')->assertSee('Holiday notice')->assertDontSee('Daily MCQ')->assertDontSee('Current affairs')
            ->set('type', 'pdf')->assertSee('Current affairs')->assertDontSee('Holiday notice')
            ->set('type', 'image')->assertSee('Daily MCQ')->assertDontSee('Current affairs');
    }

    public function test_sorting(): void
    {
        MessageTemplate::factory()->create(['title' => 'Zebra', 'usage_count' => 1, 'last_used_at' => now()]);
        MessageTemplate::factory()->create(['title' => 'Apple', 'usage_count' => 9, 'last_used_at' => now()->subDays(5)]);

        Livewire::test(Index::class)
            ->assertSeeInOrder(['Zebra', 'Apple'])     // recently used
            ->set('sort', 'popular')->assertSeeInOrder(['Apple', 'Zebra'])
            ->set('sort', 'title')->assertSeeInOrder(['Apple', 'Zebra']);
    }

    public function test_admin_can_create_a_marathi_template_with_tags_and_attachment(): void
    {
        $media = Media::factory()->create();

        Livewire::test(EditTemplate::class)
            ->call('create')
            ->set('form.title', '  साप्ताहिक निकाल  ')
            ->set('form.category_id', $this->mpsc->id)
            ->set('form.message', "*साप्ताहिक चाचणी निकाल* 🏆\nतुमचा निकाल पाहा.")
            ->call('addTag', '#Weekly Test')
            ->call('addTag', 'results, निकाल')
            ->call('addTag', 'results') // duplicate ignored
            ->dispatch('media-picked', id: $media->id, context: EditTemplate::PICKER_CONTEXT)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('template-saved')
            ->assertDispatched('toast');

        $template = MessageTemplate::sole();
        $this->assertSame('साप्ताहिक निकाल', $template->title);
        $this->assertSame(['weekly-test', 'results', 'निकाल'], $template->tags);
        $this->assertTrue($template->attachment->is($media));
        $this->assertStringContainsString('🏆', $template->message);
    }

    public function test_media_picked_for_another_screen_is_ignored(): void
    {
        $media = Media::factory()->create();

        Livewire::test(EditTemplate::class)
            ->call('create')
            ->dispatch('media-picked', id: $media->id, context: 'send-message')
            ->assertSet('form.attachment_id', null);
    }

    public function test_validation(): void
    {
        Livewire::test(EditTemplate::class)
            ->call('create')
            ->call('save')
            ->assertHasErrors(['form.title' => 'required', 'form.message' => 'required']);

        Livewire::test(EditTemplate::class)
            ->call('create')
            ->set('form.title', 'Too long')
            ->set('form.message', str_repeat('अ', WhatsAppFormatter::MAX_LENGTH + 1))
            ->call('save')
            ->assertHasErrors('form.message');

        $this->assertSame(0, MessageTemplate::count());
    }

    public function test_tags_are_normalized_and_limited(): void
    {
        $this->assertSame('current-affairs', TemplateForm::normalizeTag('#Current Affairs'));
        $this->assertSame('mpsc-2026', TemplateForm::normalizeTag(' ##MPSC__2026! '));
        $this->assertSame('पोलीस-भरती', TemplateForm::normalizeTag('पोलीस भरती'));
        $this->assertNull(TemplateForm::normalizeTag(' # '));

        $component = Livewire::test(EditTemplate::class)->call('create');
        foreach (range(1, TemplateForm::MAX_TAGS + 3) as $i) {
            $component->call('addTag', "tag{$i}");
        }
        $this->assertCount(TemplateForm::MAX_TAGS, $component->get('form.tags'));

        $component->call('removeTag', 'tag1');
        $this->assertNotContains('tag1', $component->get('form.tags'));
    }

    public function test_admin_can_edit_a_template(): void
    {
        $template = MessageTemplate::factory()->create(['title' => 'Old', 'message' => 'old', 'tags' => ['a']]);

        Livewire::test(EditTemplate::class)
            ->call('edit', $template->id)
            ->assertSet('form.title', 'Old')
            ->set('form.message', 'नवीन मजकूर')
            ->call('removeAttachment')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('नवीन मजकूर', $template->fresh()->message);
    }

    public function test_duplicate_copies_content_but_not_usage(): void
    {
        $template = MessageTemplate::factory()->create([
            'title' => 'Daily MCQ', 'tags' => ['mpsc'], 'usage_count' => 7, 'last_used_at' => now(),
        ]);

        Livewire::test(Index::class)->call('duplicate', $template->id);

        $copy = MessageTemplate::where('title', 'Daily MCQ (copy)')->sole();
        $this->assertSame($template->message, $copy->message);
        $this->assertSame(['mpsc'], $copy->tags);
        $this->assertSame(0, $copy->usage_count);
        $this->assertNull($copy->last_used_at);
    }

    public function test_delete_asks_for_confirmation(): void
    {
        $template = MessageTemplate::factory()->create(['title' => 'Holiday notice']);

        Livewire::test(Index::class)
            ->call('confirmDelete', $template->id)
            ->assertDispatched('open-modal')
            ->assertSee('Holiday notice')
            ->call('delete')
            ->assertDispatched('toast');

        $this->assertModelMissing($template);
    }

    public function test_media_picker_filters_and_returns_the_choice_with_its_context(): void
    {
        $image = Media::factory()->create(['original_name' => 'poster.png']);
        Media::factory()->pdf()->create(['original_name' => 'notes.pdf']);

        Livewire::test(Picker::class)
            ->call('open', 'template-form')
            ->assertDispatched('open-modal')
            ->assertSee('poster.png')->assertSee('notes.pdf')
            ->set('type', 'image')->assertSee('poster.png')->assertDontSee('notes.pdf')
            ->set('type', '')->set('search', 'notes')->assertDontSee('poster.png')
            ->call('choose', $image->id)
            ->assertDispatched('media-picked', id: $image->id, context: 'template-form');
    }
}

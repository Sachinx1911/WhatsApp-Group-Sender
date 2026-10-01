<?php

namespace App\Livewire\SendMessage;

use App\Actions\Campaigns\CreateCampaign;
use App\Actions\Media\StoreUploadedMedia;
use App\Enums\CampaignStatus;
use App\Enums\GroupStatus;
use App\Livewire\Forms\SendForm;
use App\Models\Campaign;
use App\Models\Category;
use App\Models\Group;
use App\Models\Media;
use App\Models\MessageTemplate;
use App\Support\SendEstimate;
use App\Support\Settings;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;

/**
 * Send Message screen (docs/MASTER_PROMPT.md §9–10): composer, attachment,
 * WhatsApp preview, group selection, Send Test and Review & Send.
 */
#[Title('Send Message')]
class Compose extends Component
{
    use WithFileUploads, WithoutUrlPagination, WithPagination;

    public const REVIEW_MODAL = 'review-send';

    public const TEST_MODAL = 'confirm-send-test';

    public const PICKER_CONTEXT = 'send-message';

    public const GROUPS_PER_PAGE = 25;

    public SendForm $form;

    /** Browse Files / drag & drop upload (one file). */
    public $upload;

    public string $groupSearch = '';

    public string $groupCategory = '';

    /** 'active' (default), 'inactive' or 'selected' (only the ticked groups). */
    public string $groupView = 'active';

    /**
     * Prefill from links elsewhere in the app: ?template=ID (Templates "Use"),
     * ?campaign=ID (Send History "Send again"), ?media=ID (Media Library "Use in Message")
     * and ?groups[]=ID (Group details).
     */
    public function mount(): void
    {
        if ($template = request()->integer('template')) {
            $this->applyTemplate($template, notify: false);
        }

        // "Send again" from Send History: same message, attachment and (still active) groups.
        if ($previous = Campaign::find(request()->integer('campaign'))) {
            $this->form->message = $previous->message;
            $this->form->syncAttachments($previous->attachments->pluck('id')->all()
                ?: array_filter([$previous->attachment_id]));
            $this->form->groups = Group::active()
                ->whereIn('id', $previous->campaignGroups()->whereNotNull('group_id')->select('group_id'))
                ->pluck('id')->map(fn ($id) => (string) $id)->all();
        }

        if (($media = request()->integer('media')) && Media::whereKey($media)->exists()) {
            $this->form->addAttachment($media);
        }

        if ($groups = array_filter(array_map('intval', (array) request()->query('groups', [])))) {
            $this->form->groups = Group::active()->whereKey($groups)->pluck('id')->map(fn ($id) => (string) $id)->all();
        }

        // Settings → Group Settings → Remember previous group selection.
        if (! $this->form->groups && config('educationhub.groups.remember_selection')) {
            $this->form->groups = Group::active()->whereKey((array) config('educationhub.groups.last_selection'))
                ->pluck('id')->map(fn ($id) => (string) $id)->all();
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['groupSearch', 'groupCategory', 'groupView'], true)) {
            $this->resetPage('groupsPage');
        }
    }

    // ---- Message -----------------------------------------------------------

    #[On('template-picked')]
    public function applyTemplate(int $id, bool $notify = true): void
    {
        $template = MessageTemplate::find($id);

        if (! $template) {
            return;
        }

        $this->form->message = $template->message;
        $this->form->template_id = $template->id;

        $fromTemplate = $template->attachments->pluck('id')->all()
            ?: array_filter([$template->attachment_id]);

        if ($fromTemplate !== []) {
            $this->form->syncAttachments($fromTemplate);
        }

        $this->resetValidation('form.message');

        if ($notify) {
            $this->dispatch('toast', type: 'success', message: "Template “{$template->title}” inserted");
        }
    }

    public function clearMessage(): void
    {
        $this->form->message = '';
        $this->form->template_id = null;
    }

    // ---- Attachment --------------------------------------------------------

    public function updatedUpload(): void
    {
        try {
            $media = app(StoreUploadedMedia::class)->handle($this->upload, 'upload');
            $this->form->addAttachment($media->id);
            unset($this->attachments);
            $this->resetValidation('form.message');
            $this->dispatch('toast', type: 'success', message: "“{$media->original_name}” attached and saved to the Media Library");
        } catch (ValidationException $e) {
            $this->addError('upload', collect($e->errors())->flatten()->first());
        } finally {
            $this->reset('upload');
        }
    }

    #[On('media-picked')]
    public function attachFromLibrary(int $id, string $context): void
    {
        if ($context !== self::PICKER_CONTEXT || ! Media::whereKey($id)->exists()) {
            return;
        }

        if (count($this->form->attachment_ids) >= SendForm::MAX_ATTACHMENTS) {
            $this->dispatch('toast', type: 'warning', message: 'You can attach at most '.SendForm::MAX_ATTACHMENTS.' files to one message.');

            return;
        }

        $this->form->addAttachment($id);
        unset($this->attachments);
        $this->resetValidation(['form.message', 'upload']);
    }

    public function removeAttachment(?int $id = null): void
    {
        // No id means the older single-attachment control: clear everything.
        $id === null
            ? $this->form->syncAttachments([])
            : $this->form->removeAttachment($id);

        unset($this->attachments);
    }

    /** Every attached file, in send order. */
    #[Computed]
    public function attachments()
    {
        $ids = $this->form->attachmentIds();

        if ($ids === []) {
            return collect();
        }

        $byId = Media::whereKey($ids)->get()->keyBy('id');

        // Preserve the admin's order, and drop anything deleted from the library meanwhile.
        return collect($ids)->map(fn (int $id) => $byId->get($id))->filter()->values();
    }

    /**
     * True when images and PDFs are mixed. WhatsApp cannot carry both in one message, so
     * each group receives two, and the admin should know that before sending.
     */
    #[Computed]
    public function mixedAttachmentKinds(): bool
    {
        $kinds = $this->attachments->map(fn (Media $media) => $media->isImage() ? 'image' : 'document')->unique();

        return $kinds->count() > 1;
    }

    /** The first attachment, for screens and previews that show just one. */
    #[Computed]
    public function attachment(): ?Media
    {
        return $this->attachments->first();
    }

    // ---- Groups ------------------------------------------------------------

    /** Selects every active group matching the current search and category, across all pages. */
    public function selectAllMatching(): void
    {
        // Ids are kept as strings, like the values of the checkboxes.
        $ids = $this->groupQuery(forSelection: true)->pluck('id')->map(fn ($id) => (string) $id)->all();

        $this->form->groups = array_values(array_unique([...$this->form->groups, ...$ids]));
        $this->resetValidation('form.groups');
    }

    public function clearSelection(): void
    {
        $this->form->groups = [];

        if ($this->groupView === 'selected') {
            $this->groupView = 'active';
        }
    }

    private function groupQuery(bool $forSelection = false)
    {
        $selected = array_map('intval', $this->form->groups);

        return Group::with('category')
            ->filter(['search' => $this->groupSearch, 'category' => $this->groupCategory])
            ->when($forSelection || $this->groupView === 'active', fn ($q) => $q->where('status', GroupStatus::Active))
            ->when(! $forSelection && $this->groupView === 'inactive', fn ($q) => $q->where('status', GroupStatus::Inactive))
            ->when(! $forSelection && $this->groupView === 'selected', fn ($q) => $q->whereKey($selected))
            ->orderBy('name');
    }

    /** @return array{count: int, members: int, categories: array<string, int>} */
    #[Computed]
    public function selection(): array
    {
        $groups = Group::with('category')->whereKey(array_map('intval', $this->form->groups))->get();

        return [
            'count' => $groups->count(),
            'members' => (int) $groups->sum('member_count'),
            'categories' => $groups->countBy(fn (Group $g) => $g->category->name)->sortDesc()->all(),
        ];
    }

    #[Computed]
    public function categories()
    {
        return Category::ordered()->get();
    }

    // ---- Send Test / Review & Send -----------------------------------------

    #[Computed]
    public function testGroup(): ?Group
    {
        return Group::testGroup();
    }

    public function sendTest(): void
    {
        $this->form->validateMessage();

        if (! $this->testGroup || ! $this->testGroup->isActive()) {
            $this->dispatch('toast', type: 'error', message: 'No active test group. Add a group named “Education Hub Test Group”, or choose one in Settings.');

            return;
        }

        $this->dispatch('open-modal', self::TEST_MODAL);
    }

    public function review(): void
    {
        $this->form->confirmCount = '';
        $this->resetValidation('form.confirmCount');
        $this->form->validate([...$this->form->messageRules(), ...$this->form->groupRules()]);

        $this->dispatch('open-modal', self::REVIEW_MODAL);
    }

    public function startSending(CreateCampaign $create): void
    {
        $this->form->validateForSending();
        $scheduledAt = $this->form->scheduledAt();

        $campaign = $create->handle(
            message: $this->form->finalMessage(),
            attachment: $this->attachment,
            groupIds: $this->form->groups,
            template: $this->form->template_id ? MessageTemplate::find($this->form->template_id) : null,
            user: auth()->user(),
            scheduledAt: $scheduledAt,
        );

        if (config('educationhub.groups.remember_selection')) {
            Settings::set(['educationhub.groups.last_selection' => array_map('intval', $this->form->groups)]);
        }

        $this->openProgress($campaign);
    }

    public function startTest(CreateCampaign $create): void
    {
        $this->form->validateMessage();

        if (! $this->testGroup?->isActive()) {
            $this->dispatch('toast', type: 'error', message: 'No active test group.');

            return;
        }

        $campaign = $create->handle(
            message: $this->form->finalMessage(),
            attachment: $this->attachment,
            groupIds: [$this->testGroup->id],
            isTest: true,
            user: auth()->user(),
        );

        $this->openProgress($campaign);
    }

    private function openProgress(Campaign $campaign): void
    {
        // Lets the page drop its "unsaved message" warning before it navigates away.
        $this->dispatch('campaign-started');
        session()->flash('toast', ['type' => 'success', 'message' => match ($campaign->status) {
            CampaignStatus::Scheduled => 'Scheduled for '.$campaign->scheduled_at->format('D, d M \a\t g:i A').'. Keep the app running at that time.',
            CampaignStatus::Queued => 'Campaign created. It starts after the campaign that is sending now.',
            default => 'Sending started',
        }]);

        // Settings → Sending → Show progress during sending.
        config('educationhub.sending.show_progress', true)
            ? $this->redirectRoute('campaigns.show', $campaign)
            : $this->redirectRoute('history.index');
    }

    public function estimate(): string
    {
        return SendEstimate::label(count($this->form->groups));
    }

    public function render()
    {
        return view('livewire.send-message.compose', [
            'groups' => $this->groupQuery()->paginate(self::GROUPS_PER_PAGE, pageName: 'groupsPage'),
        ]);
    }
}

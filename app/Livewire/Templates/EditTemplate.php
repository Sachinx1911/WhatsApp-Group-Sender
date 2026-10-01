<?php

namespace App\Livewire\Templates;

use App\Livewire\Forms\TemplateForm;
use App\Models\Category;
use App\Models\Media;
use App\Models\MessageTemplate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/** Create / edit template modal with a live WhatsApp preview. */
class EditTemplate extends Component
{
    public const MODAL = 'edit-template';

    public const PICKER_CONTEXT = 'template-form';

    public TemplateForm $form;

    #[On('create-template')]
    public function create(): void
    {
        $this->form->reset();
        $this->form->resetValidation();

        $this->dispatch('open-modal', self::MODAL);
    }

    #[On('edit-template')]
    public function edit(int $id): void
    {
        $this->form->resetValidation();
        $this->form->setTemplate(MessageTemplate::findOrFail($id));

        $this->dispatch('open-modal', self::MODAL);
    }

    public function addTag(string $input): void
    {
        $this->form->addTag($input);
    }

    public function removeTag(string $tag): void
    {
        $this->form->removeTag($tag);
    }

    #[On('media-picked')]
    public function attach(int $id, string $context): void
    {
        if ($context !== self::PICKER_CONTEXT) {
            return;
        }

        if (count($this->form->attachment_ids) >= TemplateForm::MAX_ATTACHMENTS) {
            $this->dispatch('toast', type: 'warning', message: 'A template can hold at most '.TemplateForm::MAX_ATTACHMENTS.' files.');

            return;
        }

        $this->form->addAttachment(Media::findOrFail($id)->id);
        unset($this->attachments);
    }

    public function removeAttachment(?int $id = null): void
    {
        $id === null
            ? $this->form->syncAttachments([])
            : $this->form->removeAttachment($id);

        unset($this->attachments);
    }

    public function save(): void
    {
        $creating = ! $this->form->template;
        $template = $this->form->save();

        $this->dispatch('close-modal', self::MODAL);
        $this->dispatch('template-saved', id: $template->id);
        $this->dispatch('toast', type: 'success', message: $creating ? "Template “{$template->title}” created" : "Template “{$template->title}” updated");
        $this->form->reset();
    }

    /** Every file on this template, in order. */
    #[Computed]
    public function attachments()
    {
        if ($this->form->attachment_ids === []) {
            return collect();
        }

        $byId = Media::whereKey($this->form->attachment_ids)->get()->keyBy('id');

        return collect($this->form->attachment_ids)->map(fn (int $id) => $byId->get($id))->filter()->values();
    }

    #[Computed]
    public function attachment(): ?Media
    {
        return $this->attachments->first();
    }

    #[Computed]
    public function categories()
    {
        return Category::ordered()->get();
    }

    public function render()
    {
        return view('livewire.templates.edit-template');
    }
}

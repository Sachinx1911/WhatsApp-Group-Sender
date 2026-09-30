<?php

namespace App\Livewire\Forms;

use App\Enums\GroupStatus;
use App\Models\Group;
use App\Models\Media;
use App\Support\WhatsAppFormatter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Form;

/**
 * The message being composed on the Send Message screen.
 * An attachment may be sent without text (as WhatsApp allows); otherwise text is required.
 */
class SendForm extends Form
{
    public string $message = '';

    public ?int $attachment_id = null;

    /** @var array<int, int> selected group ids */
    public array $groups = [];

    /** Template the message came from, so its usage can be counted when sent. */
    public ?int $template_id = null;

    /** Number typed by the admin to confirm a large selection. */
    public string $confirmCount = '';

    public function messageRules(): array
    {
        return [
            'message' => ['nullable', 'string', 'required_without:attachment_id', function ($attribute, $value, $fail) {
                if (WhatsAppFormatter::length($value) > WhatsAppFormatter::MAX_LENGTH) {
                    $fail('The message may not be longer than '.number_format(WhatsAppFormatter::MAX_LENGTH).' characters.');
                }
            }],
            'attachment_id' => ['nullable', 'integer', Rule::exists(Media::class, 'id')],
        ];
    }

    public function groupRules(): array
    {
        $max = (int) config('educationhub.sending.max_groups_per_campaign', 300);

        return [
            'groups' => ['required', 'array', 'min:1', "max:{$max}"],
            'groups.*' => ['integer', Rule::exists(Group::class, 'id')->where('status', GroupStatus::Active->value)],
        ];
    }

    public function messages(): array
    {
        return [
            'message.required_without' => 'Write a message or attach an image or PDF.',
            'groups.required' => 'Select at least one group.',
            'groups.min' => 'Select at least one group.',
            'groups.max' => 'Select at most :max groups in one campaign.',
            'groups.*.exists' => 'One of the selected groups is inactive or no longer exists. Refresh the list and try again.',
        ];
    }

    /** Content only (used by Send Test). */
    public function validateMessage(): void
    {
        $this->validate($this->messageRules());
    }

    /** Content, groups and — for large selections — the typed confirmation. */
    public function validateForSending(): void
    {
        $this->validate([...$this->messageRules(), ...$this->groupRules()]);

        if ($this->needsTypedConfirmation() && (int) trim($this->confirmCount) !== count($this->groups)) {
            throw ValidationException::withMessages([
                'form.confirmCount' => 'Type '.count($this->groups).' to confirm sending to this many groups.',
            ]);
        }
    }

    public function needsTypedConfirmation(): bool
    {
        return count($this->groups) > (int) config('educationhub.sending.large_selection_threshold', 50);
    }

    /** Message text exactly as it will be sent (with the footer when auto-footer is on). */
    public function finalMessage(): string
    {
        $footer = trim((string) config('educationhub.message.footer'));

        return config('educationhub.message.auto_add_footer') && $footer !== ''
            ? rtrim($this->message)."\n\n".$footer
            : $this->message;
    }
}

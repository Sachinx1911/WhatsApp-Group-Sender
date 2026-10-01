<?php

namespace App\Livewire\Forms;

use App\Enums\GroupStatus;
use App\Models\Group;
use App\Models\Media;
use App\Support\WhatsAppFormatter;
use Carbon\CarbonImmutable;
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

    /**
     * First attachment. Derived from attachment_ids and kept so the single-attachment
     * screens and the campaigns.attachment_id column keep working.
     */
    public ?int $attachment_id = null;

    /** @var array<int, int> every attached media id, in send order */
    public array $attachment_ids = [];

    /** @var array<int, int> selected group ids */
    public array $groups = [];

    /** Template the message came from, so its usage can be counted when sent. */
    public ?int $template_id = null;

    /** Number typed by the admin to confirm a large selection. */
    public string $confirmCount = '';

    /** 'now' or 'later' (Review & Send). */
    public string $when = 'now';

    /** Local date-time chosen for 'later', as the <input type="datetime-local"> gives it: "2026-10-02T07:00". */
    public string $scheduledFor = '';

    /** The chosen time as a date in the app timezone, or null for "send now". @throws ValidationException */
    public function scheduledAt(): ?CarbonImmutable
    {
        if ($this->when !== 'later') {
            return null;
        }

        $at = $this->scheduledFor !== '' ? CarbonImmutable::createFromFormat('Y-m-d\TH:i', $this->scheduledFor) : null;

        if (! $at) {
            throw ValidationException::withMessages(['form.scheduledFor' => 'Choose the date and time to send.']);
        }

        if ($at->lte(now()->addMinute())) {
            throw ValidationException::withMessages(['form.scheduledFor' => 'Choose a time at least a few minutes from now.']);
        }

        if ($at->gt(now()->addDays(60))) {
            throw ValidationException::withMessages(['form.scheduledFor' => 'Schedule at most 60 days ahead.']);
        }

        return $at->startOfMinute();
    }

    /** Most files WhatsApp will accept in one go before the preview becomes unwieldy. */
    public const MAX_ATTACHMENTS = 10;

    /**
     * The files to send. Falls back to attachment_id when only that was set: older code
     * paths and deep links still assign the single property, and losing their attachment
     * silently would be worse than carrying it.
     *
     * @return array<int, int>
     */
    public function attachmentIds(): array
    {
        if ($this->attachment_ids !== []) {
            return $this->attachment_ids;
        }

        return $this->attachment_id ? [$this->attachment_id] : [];
    }

    public function hasAttachment(): bool
    {
        return $this->attachmentIds() !== [];
    }

    /** Keep attachment_id pointing at the first file in the list. */
    public function syncAttachments(array $ids): void
    {
        $this->attachment_ids = array_values(array_unique(array_map('intval', $ids)));
        $this->attachment_id = $this->attachment_ids[0] ?? null;
    }

    public function addAttachment(int $id): void
    {
        $this->syncAttachments([...$this->attachment_ids, $id]);
    }

    public function removeAttachment(int $id): void
    {
        $this->syncAttachments(array_filter($this->attachment_ids, fn ($existing) => $existing !== $id));
    }

    public function messageRules(): array
    {
        return [
            // required_without_all, not a closure: Laravel skips non-implicit rules when the
            // value is empty, so a closure here would never run for a blank message and an
            // empty campaign would sail through.
            'message' => ['nullable', 'string', 'required_without_all:attachment_id,attachment_ids', function ($attribute, $value, $fail) {
                if (WhatsAppFormatter::length($value) > WhatsAppFormatter::MAX_LENGTH) {
                    $fail('The message may not be longer than '.number_format(WhatsAppFormatter::MAX_LENGTH).' characters.');
                }
            }],
            'attachment_id' => ['nullable', 'integer', Rule::exists(Media::class, 'id')],
            'attachment_ids' => ['nullable', 'array', 'max:'.self::MAX_ATTACHMENTS],
            'attachment_ids.*' => ['integer', Rule::exists(Media::class, 'id')],
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
            'message.required_without_all' => 'Write a message or attach an image or PDF.',
            'attachment_ids.max' => 'Attach at most :max files to one message.',
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

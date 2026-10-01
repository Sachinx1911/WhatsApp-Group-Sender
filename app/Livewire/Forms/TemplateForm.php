<?php

namespace App\Livewire\Forms;

use App\Models\MessageTemplate;
use App\Support\WhatsAppFormatter;
use Illuminate\Validation\Rule;
use Livewire\Form;

class TemplateForm extends Form
{
    public const MAX_TAGS = 10;

    public const MAX_TAG_LENGTH = 30;

    public ?MessageTemplate $template = null;

    public string $title = '';

    public ?int $category_id = null;

    public string $message = '';

    /** First attachment, kept so single-attachment screens keep working. */
    public ?int $attachment_id = null;

    /** @var array<int, int> every attached media id, in order */
    public array $attachment_ids = [];

    /** @var array<int, string> */
    public array $tags = [];

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'message' => ['required', 'string', function ($attribute, $value, $fail) {
                if (WhatsAppFormatter::length($value) > WhatsAppFormatter::MAX_LENGTH) {
                    $fail('The message may not be longer than '.number_format(WhatsAppFormatter::MAX_LENGTH).' characters.');
                }
            }],
            'attachment_id' => ['nullable', 'integer', Rule::exists('media', 'id')],
            'attachment_ids' => ['nullable', 'array', 'max:'.self::MAX_ATTACHMENTS],
            'attachment_ids.*' => ['integer', Rule::exists('media', 'id')],
            'tags' => ['array', 'max:'.self::MAX_TAGS],
            'tags.*' => ['string', 'max:'.self::MAX_TAG_LENGTH],
        ];
    }

    public function messages(): array
    {
        return ['tags.max' => 'Use at most '.self::MAX_TAGS.' tags.'];
    }

    public function setTemplate(MessageTemplate $template): void
    {
        $this->template = $template;
        $this->title = $template->title;
        $this->category_id = $template->category_id;
        $this->message = $template->message;
        // Older templates only have the single column; treat it as a one-item list.
        $this->syncAttachments($template->attachments->pluck('id')->all() ?: array_filter([$template->attachment_id]));
        $this->tags = $template->tags ?? [];
    }

    /** "#Current Affairs" → "current-affairs". Keeps Marathi letters. Returns null if nothing is left. */
    public static function normalizeTag(string $tag): ?string
    {
        $tag = mb_strtolower(trim(ltrim(trim($tag), '#')));
        $tag = preg_replace('/[^\p{L}\p{M}\p{N}]+/u', '-', $tag);
        $tag = mb_substr(trim($tag, '-'), 0, self::MAX_TAG_LENGTH);

        return $tag === '' ? null : $tag;
    }

    public function addTag(string $input): void
    {
        foreach (explode(',', $input) as $raw) {
            $tag = self::normalizeTag($raw);

            if ($tag && ! in_array($tag, $this->tags, true) && count($this->tags) < self::MAX_TAGS) {
                $this->tags[] = $tag;
            }
        }
    }

    public function removeTag(string $tag): void
    {
        $this->tags = array_values(array_diff($this->tags, [$tag]));
    }

    /** Most files one template may carry. */
    public const MAX_ATTACHMENTS = 10;

    /** Keep attachment_id pointing at the first file. */
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

    public function save(): MessageTemplate
    {
        $this->title = trim($this->title);
        $data = $this->validate();

        // attachment_ids lives in the pivot, not on the row.
        $ids = $this->attachment_ids;
        unset($data['attachment_ids']);

        $template = $this->template ?? new MessageTemplate;
        $template->fill($data)->save();

        $template->attachments()->sync(
            collect($ids)->mapWithKeys(fn (int $id, int $i) => [$id => ['position' => $i]])->all()
        );

        return $template->load('attachments');
    }
}

<?php

namespace App\Livewire\Forms;

use App\Enums\GroupStatus;
use App\Models\Group;
use Illuminate\Validation\Rule;
use Livewire\Form;

class GroupForm extends Form
{
    public const MAX_MEMBERS = 5000;

    public ?Group $group = null;

    public string $name = '';

    public ?int $category_id = null;

    public ?int $member_count = null;

    public string $whatsapp_identifier = '';

    public string $status = 'active';

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('groups', 'name')->ignore($this->group)],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],
            'member_count' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_MEMBERS],
            'whatsapp_identifier' => ['nullable', 'string', 'max:255', Rule::unique('groups', 'whatsapp_identifier')->ignore($this->group)],
            'status' => ['required', Rule::enum(GroupStatus::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'A group with this name already exists. Group names must be unique.',
            'category_id.required' => 'Choose a category.',
        ];
    }

    public function setGroup(Group $group): void
    {
        $this->group = $group;
        $this->name = $group->name;
        $this->category_id = $group->category_id;
        $this->member_count = $group->member_count;
        $this->whatsapp_identifier = (string) $group->whatsapp_identifier;
        $this->status = $group->status->value;
    }

    public function save(): Group
    {
        // WhatsApp matches names exactly; stray spaces around the name are never intended.
        $this->name = trim($this->name);
        $this->whatsapp_identifier = trim($this->whatsapp_identifier);

        $data = $this->validate();
        $data['whatsapp_identifier'] = $data['whatsapp_identifier'] ?: null;

        $group = $this->group ?? new Group;
        $group->fill($data)->save();

        return $group;
    }
}

<?php

namespace App\Livewire\Groups;

use App\Livewire\Forms\GroupForm;
use App\Models\Category;
use App\Models\Group;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/** Add / edit group modal. Opened with the 'create-group' or 'edit-group' events. */
class EditGroup extends Component
{
    public const MODAL = 'edit-group';

    public GroupForm $form;

    #[On('create-group')]
    public function create(): void
    {
        $this->form->reset();
        $this->form->resetValidation();
        $this->form->category_id = Category::ordered()->value('id');

        $this->dispatch('open-modal', self::MODAL);
    }

    #[On('edit-group')]
    public function edit(int $id): void
    {
        $this->form->resetValidation();
        $this->form->setGroup(Group::findOrFail($id));

        $this->dispatch('open-modal', self::MODAL);
    }

    public function save(): void
    {
        $creating = ! $this->form->group;
        $group = $this->form->save();

        $this->dispatch('close-modal', self::MODAL);
        $this->dispatch('group-saved', id: $group->id);
        $this->dispatch('toast', type: 'success', message: $creating ? "Group “{$group->name}” added" : "Group “{$group->name}” updated");

        $this->form->reset();
    }

    #[On('categories-changed')]
    public function refreshCategories(): void
    {
        unset($this->categories);
    }

    #[Computed]
    public function categories()
    {
        return Category::ordered()->get();
    }

    public function render()
    {
        return view('livewire.groups.edit-group');
    }
}

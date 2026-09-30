<?php

namespace App\Livewire\Categories;

use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Category management modal (docs/MASTER_PROMPT.md §13): add, rename, recolor,
 * reorder and delete. Categories that still have groups cannot be deleted.
 */
class Manager extends Component
{
    public const MODAL = 'manage-categories';

    public string $newName = '';

    public string $newColor = Category::PALETTE[0];

    public ?int $editingId = null;

    public string $editName = '';

    public string $editColor = '';

    #[Computed]
    public function categories()
    {
        return Category::ordered()->withCount('groups')->get();
    }

    public function add(): void
    {
        $this->newName = trim($this->newName);

        $this->validate([
            'newName' => ['required', 'string', 'max:100', Rule::unique('categories', 'name')],
            'newColor' => ['required', Rule::in(Category::PALETTE)],
        ], ['newName.unique' => 'This category already exists.'], ['newName' => 'category name']);

        Category::create([
            'name' => $this->newName,
            'color' => $this->newColor,
            'sort_order' => (int) Category::max('sort_order') + 1,
        ]);

        $this->dispatch('toast', type: 'success', message: "Category “{$this->newName}” added");
        $this->reset('newName');
        $this->changed();
    }

    public function edit(int $id): void
    {
        $category = Category::findOrFail($id);

        $this->resetValidation();
        $this->editingId = $category->id;
        $this->editName = $category->name;
        $this->editColor = $category->color ?? Category::PALETTE[0];
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'editName', 'editColor');
        $this->resetValidation();
    }

    public function update(): void
    {
        $this->editName = trim($this->editName);

        $this->validate([
            'editName' => ['required', 'string', 'max:100', Rule::unique('categories', 'name')->ignore($this->editingId)],
            'editColor' => ['required', Rule::in(Category::PALETTE)],
        ], ['editName.unique' => 'Another category already has this name.'], ['editName' => 'category name']);

        Category::findOrFail($this->editingId)->update(['name' => $this->editName, 'color' => $this->editColor]);

        $this->cancelEdit();
        $this->changed();
    }

    /** Swap a category with its neighbour in the display order. */
    public function move(int $id, string $direction): void
    {
        $ordered = Category::ordered()->pluck('id')->values();
        $index = $ordered->search($id);
        $target = $direction === 'up' ? $index - 1 : $index + 1;

        if ($index === false || ! $ordered->has($target)) {
            return;
        }

        $ordered[$index] = $ordered[$target];
        $ordered[$target] = $id;

        DB::transaction(function () use ($ordered) {
            foreach ($ordered as $position => $categoryId) {
                Category::whereKey($categoryId)->update(['sort_order' => $position]);
            }
        });

        $this->changed();
    }

    public function delete(int $id): void
    {
        $category = Category::withCount('groups')->findOrFail($id);

        if ($category->groups_count > 0) {
            $this->dispatch('toast', type: 'error',
                message: "“{$category->name}” still has {$category->groups_count} ".str('group')->plural($category->groups_count).'. Move them to another category first.');

            return;
        }

        $category->delete();
        $this->dispatch('toast', type: 'success', message: "Category “{$category->name}” deleted");
        $this->changed();
    }

    private function changed(): void
    {
        unset($this->categories);
        $this->dispatch('categories-changed');
    }

    public function render()
    {
        return view('livewire.categories.manager');
    }
}

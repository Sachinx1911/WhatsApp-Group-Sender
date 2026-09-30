<?php

namespace App\Livewire\Templates;

use App\Models\Category;
use App\Models\MessageTemplate;
use App\Support\Pagination;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Message Templates')]
class Index extends Component
{
    use WithPagination;

    public const CONFIRM_MODAL = 'confirm-delete-template';

    /** sort key => [column, direction] */
    public const SORTS = [
        'recent' => ['Recently used', 'last_used_at', 'desc'],
        'popular' => ['Most used', 'usage_count', 'desc'],
        'title' => ['Title A–Z', 'title', 'asc'],
        'newest' => ['Newest first', 'created_at', 'desc'],
    ];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $category = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: 'recent')]
    public string $sort = 'recent';

    #[Url(as: 'per_page', except: 25)]
    public int $perPage = 25;

    public ?int $deletingId = null;

    /** Kept after deleting so the closing modal does not flash an empty name. */
    public string $deletingTitle = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'category', 'type', 'sort', 'perPage'], true)) {
            if (! in_array($this->perPage, Pagination::PER_PAGE_OPTIONS, true)) {
                $this->perPage = Pagination::PER_PAGE_OPTIONS[0];
            }
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'category', 'type');
        $this->resetPage();
    }

    #[Computed]
    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->category !== '' || $this->type !== '';
    }

    #[Computed]
    public function categories()
    {
        return Category::ordered()->get();
    }

    #[On('template-saved')]
    public function refreshList(): void
    {
        // Re-render picks up the saved template.
    }

    public function duplicate(int $id): void
    {
        $original = MessageTemplate::findOrFail($id);

        $copy = $original->replicate(['usage_count', 'last_used_at']);
        $copy->title = mb_substr($original->title.' (copy)', 0, 120);
        $copy->usage_count = 0;
        $copy->save();

        $this->dispatch('toast', type: 'success', message: "Duplicated as “{$copy->title}”");
    }

    public function confirmDelete(int $id): void
    {
        $template = MessageTemplate::findOrFail($id);
        $this->deletingId = $template->id;
        $this->deletingTitle = $template->title;
        $this->dispatch('open-modal', self::CONFIRM_MODAL);
    }

    public function delete(): void
    {
        $template = MessageTemplate::findOrFail($this->deletingId);
        $template->delete();

        $this->reset('deletingId');
        $this->dispatch('close-modal', self::CONFIRM_MODAL);
        $this->dispatch('toast', type: 'success', message: "Template “{$template->title}” deleted");
    }

    public function paginationView(): string
    {
        return 'livewire.partials.pagination';
    }

    public function render()
    {
        [, $column, $direction] = self::SORTS[$this->sort] ?? self::SORTS['recent'];

        $templates = MessageTemplate::with(['category', 'attachment'])
            ->filter(['search' => $this->search, 'category' => $this->category, 'type' => $this->type])
            ->orderBy($column, $direction)
            ->latest('id')
            ->paginate($this->perPage);

        return view('livewire.templates.index', ['templates' => $templates]);
    }
}

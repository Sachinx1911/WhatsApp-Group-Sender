<?php

namespace App\Livewire\Media;

use App\Enums\MediaType;
use App\Models\Media;
use App\Support\Pagination;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Media Library')]
class Index extends Component
{
    use WithPagination;

    public const PREVIEW_MODAL = 'preview-media';

    public const RENAME_MODAL = 'rename-media';

    public const DELETE_MODAL = 'confirm-delete-media';

    /** sort key => [label, column, direction] */
    public const SORTS = [
        'recent' => ['Recent', 'created_at', 'desc'],
        'popular' => ['Most used', 'usage_count', 'desc'],
        'name' => ['Name A–Z', 'original_name', 'asc'],
        'largest' => ['Largest first', 'size', 'desc'],
    ];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: 'recent')]
    public string $sort = 'recent';

    #[Url(except: 'grid')]
    public string $view = 'grid';

    #[Url(as: 'per_page', except: 25)]
    public int $perPage = 25;

    public ?int $activeId = null;

    public string $renameBase = '';

    /** Name shown in the delete dialog; kept after deleting so the closing dialog does not go blank. */
    public string $activeName = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'type', 'sort', 'perPage'], true)) {
            if (! in_array($this->perPage, Pagination::PER_PAGE_OPTIONS, true)) {
                $this->perPage = Pagination::PER_PAGE_OPTIONS[0];
            }
            $this->resetPage();
        }
    }

    #[On('media-uploaded')]
    public function refreshAfterUpload(): void
    {
        unset($this->stats);
        $this->reset('search', 'type');
        $this->sort = 'recent';
        $this->resetPage();
    }

    #[Computed]
    public function stats(): array
    {
        $rows = Media::query()->selectRaw('type, count(*) as files')->groupBy('type')->get()->keyBy(fn ($r) => $r->type->value);

        return [
            'total' => (int) $rows->sum('files'),
            'images' => (int) ($rows['image']->files ?? 0),
            'pdfs' => (int) ($rows['pdf']->files ?? 0),
        ];
    }

    /** The item open in the preview / rename / delete dialog. */
    #[Computed]
    public function active(): ?Media
    {
        return $this->activeId ? Media::withCount('templates')->find($this->activeId) : null;
    }

    public function preview(int $id): void
    {
        $this->activeId = Media::findOrFail($id)->id;
        unset($this->active);
        $this->dispatch('open-modal', self::PREVIEW_MODAL);
    }

    public function startRename(int $id): void
    {
        $media = Media::findOrFail($id);

        $this->resetValidation();
        $this->activeId = $media->id;
        $this->renameBase = pathinfo($media->original_name, PATHINFO_FILENAME);
        unset($this->active);
        $this->dispatch('open-modal', self::RENAME_MODAL);
    }

    public function rename(): void
    {
        $this->renameBase = trim($this->renameBase);

        $this->validate(
            ['renameBase' => ['required', 'string', 'max:150', 'not_regex:/[\x00-\x1F\x7F\/\\\\:*?"<>|]/u']],
            ['renameBase.not_regex' => 'A file name cannot contain / \\ : * ? " < > or |.'],
            ['renameBase' => 'file name'],
        );

        $media = Media::findOrFail($this->activeId);
        $media->update(['original_name' => $this->renameBase.'.'.pathinfo($media->original_name, PATHINFO_EXTENSION)]);

        $this->dispatch('close-modal', self::RENAME_MODAL);
        $this->dispatch('toast', type: 'success', message: "Renamed to “{$media->original_name}”");
    }

    public function confirmDelete(int $id): void
    {
        $media = Media::findOrFail($id);
        $this->activeId = $media->id;
        $this->activeName = $media->original_name;
        unset($this->active);
        $this->dispatch('close-modal', self::PREVIEW_MODAL);
        $this->dispatch('open-modal', self::DELETE_MODAL);
    }

    public function delete(): void
    {
        $media = Media::findOrFail($this->activeId);

        if ($media->isNeededByActiveCampaign()) {
            $this->dispatch('toast', type: 'error', message: "“{$media->original_name}” is attached to a campaign that is still sending. Delete it after the campaign finishes.");

            return;
        }

        $media->deleteWithFiles();

        unset($this->stats);
        $this->dispatch('close-modal', self::DELETE_MODAL);
        $this->dispatch('toast', type: 'success', message: "“{$media->original_name}” deleted");
    }

    public function paginationView(): string
    {
        return 'livewire.partials.pagination';
    }

    public function render()
    {
        [, $column, $direction] = self::SORTS[$this->sort] ?? self::SORTS['recent'];

        $items = Media::query()
            ->when(trim($this->search) !== '', fn ($q) => $q->where('original_name', 'like', '%'.trim($this->search).'%'))
            ->when(MediaType::tryFrom($this->type), fn ($q, MediaType $type) => $q->where('type', $type))
            ->orderBy($column, $direction)
            ->latest('id')
            ->paginate($this->perPage);

        return view('livewire.media.index', ['items' => $items]);
    }
}

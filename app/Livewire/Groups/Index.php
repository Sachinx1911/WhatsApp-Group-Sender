<?php

namespace App\Livewire\Groups;

use App\Enums\GroupStatus;
use App\Livewire\Concerns\WithTable;
use App\Models\Category;
use App\Models\Group;
use App\Services\WhatsApp\PlaywrightWhatsAppService;
use App\Services\WhatsApp\WhatsAppServiceInterface;
use App\Services\WhatsApp\WhatsAppSessionManager;
use App\Services\WhatsApp\WorkerUnavailableException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Group Manager')]
class Index extends Component
{
    use WithTable;

    public const SORTABLE = ['name', 'member_count', 'last_sent_at', 'created_at'];

    public const CONFIRM_MODAL = 'confirm-delete-groups';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $category = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(as: 'min_members', except: '')]
    public string $minMembers = '';

    #[Url(as: 'max_members', except: '')]
    public string $maxMembers = '';

    #[Url(as: 'sort', except: 'name')]
    public string $sortBy = 'name';

    #[Url(as: 'dir', except: 'asc')]
    public string $sortDir = 'asc';

    /** @var array<int, string> ids selected on the current page(s) */
    public array $selected = [];

    /** Every group matching the filters is selected, across all pages. */
    public bool $selectAll = false;

    public string $bulkCategory = '';

    /** Group ids waiting for delete confirmation. */
    public array $deleting = [];

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'category', 'status', 'minMembers', 'maxMembers'], true)) {
            $this->resetPage();
            $this->clearSelection();
        }
    }

    public function filters(): array
    {
        return [
            'search' => $this->search,
            'category' => $this->category,
            'status' => $this->status,
            'min_members' => $this->minMembers,
            'max_members' => $this->maxMembers,
        ];
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'category', 'status', 'minMembers', 'maxMembers');
        $this->resetPage();
        $this->clearSelection();
    }

    public function clearSelection(): void
    {
        $this->reset('selected', 'selectAll', 'bulkCategory');
    }

    #[On('group-saved')]
    #[On('categories-changed')]
    public function refreshData(): void
    {
        unset($this->stats, $this->categories);
    }

    #[Computed]
    public function stats(): array
    {
        $row = Group::query()
            ->selectRaw('count(*) as total, sum(case when status = ? then 1 else 0 end) as active', [GroupStatus::Active->value])
            ->first();

        return ['total' => (int) $row->total, 'active' => (int) $row->active, 'inactive' => (int) $row->total - (int) $row->active];
    }

    #[Computed]
    public function categories()
    {
        return Category::ordered()->get();
    }

    #[Computed]
    public function hasFilters(): bool
    {
        return collect($this->filters())->filter(fn ($v) => $v !== '')->isNotEmpty();
    }

    private function selectedQuery(): Builder
    {
        return $this->selectAll
            ? Group::filter($this->filters())
            : Group::whereKey(array_map('intval', $this->selected));
    }

    public function toggleStatus(int $id): void
    {
        $group = Group::findOrFail($id);
        $group->update(['status' => $group->isActive() ? GroupStatus::Inactive : GroupStatus::Active]);

        unset($this->stats);
        $this->dispatch('toast', type: 'success', message: "“{$group->name}” is now {$group->status->label()}");
    }

    public function bulkSetStatus(string $status): void
    {
        $status = GroupStatus::from($status);
        $count = $this->selectedQuery()->update(['status' => $status]);

        $this->finishBulk("{$count} ".str('group')->plural($count)." set to {$status->label()}");
    }

    public function bulkChangeCategory(): void
    {
        $category = Category::find((int) $this->bulkCategory);

        if (! $category) {
            $this->addError('bulkCategory', 'Choose a category.');

            return;
        }

        $count = $this->selectedQuery()->update(['category_id' => $category->id]);

        $this->finishBulk("{$count} ".str('group')->plural($count)." moved to {$category->name}");
    }

    public function confirmDelete(?int $id = null): void
    {
        $this->deleting = $id
            ? [$id]
            : $this->selectedQuery()->pluck('id')->all();

        if ($this->deleting) {
            $this->dispatch('open-modal', self::CONFIRM_MODAL);
        }
    }

    #[Computed]
    public function deletingGroups()
    {
        return Group::whereKey($this->deleting)->orderBy('name')->limit(5)->pluck('name');
    }

    public function delete(): void
    {
        $groups = Group::whereKey($this->deleting)->get();
        [$blocked, $deletable] = $groups->partition(fn (Group $g) => $g->hasPendingSends());

        DB::transaction(fn () => Group::whereKey($deletable->modelKeys())->delete());

        $this->dispatch('close-modal', self::CONFIRM_MODAL);
        $this->reset('deleting');
        $this->finishBulk("{$deletable->count()} ".str('group')->plural($deletable->count()).' deleted');

        if ($blocked->isNotEmpty()) {
            $this->dispatch('toast', type: 'warning',
                message: $blocked->count().' '.str('group')->plural($blocked->count()).' not deleted because a campaign is still sending to '.($blocked->count() === 1 ? 'it' : 'them').'.');
        }
    }

    #[Computed]
    public function canSyncFromWhatsApp(): bool
    {
        return config('educationhub.whatsapp.driver') === 'playwright'
            && app(WhatsAppSessionManager::class)->session()->isConnected();
    }

    /**
     * Import chats from the linked WhatsApp account (docs/MASTER_PROMPT.md §14, "Sync from
     * WhatsApp"). The worker can only list chat titles, not tell groups apart from
     * one-to-one contacts, so new chats come in as Inactive: review and activate the
     * real groups before they can receive a campaign.
     */
    public function syncFromWhatsApp(WhatsAppSessionManager $sessions): void
    {
        $whatsapp = app(WhatsAppServiceInterface::class);

        if (! $whatsapp instanceof PlaywrightWhatsAppService) {
            $this->dispatch('toast', type: 'error', message: 'Sync from WhatsApp needs the Playwright worker (WHATSAPP_DRIVER=playwright).');

            return;
        }

        if (! $sessions->session()->isConnected()) {
            $this->dispatch('toast', type: 'error', message: 'Connect WhatsApp first from Settings → WhatsApp Connection.');

            return;
        }

        try {
            $chatNames = $whatsapp->listGroupNames();
        } catch (WorkerUnavailableException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }

        $existingNames = Group::pluck('name');
        $newNames = collect($chatNames)->map(fn ($n) => trim((string) $n))->filter()->unique()->diff($existingNames)->values();

        if ($newNames->isEmpty()) {
            $this->dispatch('toast', type: 'info', message: 'No new chats found. Everything WhatsApp shows is already in Group Manager.');

            return;
        }

        $defaultCategoryId = config('educationhub.groups.default_category_id') ?? Category::ordered()->value('id');

        DB::transaction(function () use ($newNames, $defaultCategoryId) {
            foreach ($newNames as $name) {
                Group::create([
                    'name' => $name,
                    'category_id' => $defaultCategoryId,
                    'status' => GroupStatus::Inactive,
                ]);
            }
        });

        unset($this->stats);
        $this->dispatch('toast', type: 'success', message: $newNames->count().' new '.str('chat')->plural($newNames->count())
            .' added as inactive. This list may include personal chats too — review and activate only real groups before sending.');
    }

    private function finishBulk(string $message): void
    {
        $this->clearSelection();
        unset($this->stats);
        $this->dispatch('toast', type: 'success', message: $message);
    }

    public function render()
    {
        $groups = Group::with('category')
            ->filter($this->filters())
            ->orderBy($this->sortColumn(), $this->sortDirection())
            ->orderBy('id')
            ->paginate($this->perPage);

        return view('livewire.groups.index', ['groups' => $groups]);
    }
}

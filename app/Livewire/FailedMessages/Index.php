<?php

namespace App\Livewire\FailedMessages;

use App\Enums\SendErrorType;
use App\Enums\SendStatus;
use App\Livewire\Concerns\WithTable;
use App\Models\CampaignGroup;
use App\Models\SendLog;
use App\Services\Campaigns\CampaignRunner;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Failed Messages with details drawer, retry and skip (docs/MASTER_PROMPT.md §18–19). */
#[Title('Failed Messages')]
class Index extends Component
{
    use WithTable;

    public const SORTABLE = ['updated_at', 'group_name'];

    public const DRAWER = 'failed-message-details';

    /** 'failed' (needs action), 'retrying' (queued again), 'skipped' (resolved) */
    #[Url(except: 'failed')]
    public string $view = 'failed';

    #[Url(except: '')]
    public string $search = '';

    #[Url(as: 'error', except: '')]
    public string $errorType = '';

    #[Url(as: 'sort', except: 'updated_at')]
    public string $sortBy = 'updated_at';

    #[Url(as: 'dir', except: 'desc')]
    public string $sortDir = 'desc';

    /** @var array<int, string> */
    public array $selected = [];

    public bool $selectAll = false;

    public ?int $activeId = null;

    /** Drawer choice: 'retry_now', 'retry_after_fix' or 'skip'. */
    public string $drawerAction = 'retry_now';

    public function updated(string $property): void
    {
        if (in_array($property, ['view', 'search', 'errorType'], true)) {
            $this->resetPage();
            $this->reset('selected', 'selectAll');
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'errorType', 'selected', 'selectAll');
        $this->resetPage();
    }

    private function query(): Builder
    {
        $term = trim($this->search);

        return CampaignGroup::query()
            ->with(['campaign:id,title,message,attachment_id,attachment_name,is_test,created_at', 'group.category'])
            ->when($this->view === 'failed', fn (Builder $q) => $q->where('status', SendStatus::Failed))
            ->when($this->view === 'retrying', fn (Builder $q) => $q->whereIn('status', [SendStatus::Pending, SendStatus::Processing])->whereNotNull('error_type'))
            ->when($this->view === 'skipped', fn (Builder $q) => $q->where('status', SendStatus::Skipped))
            ->when(SendErrorType::tryFrom($this->errorType), fn (Builder $q, SendErrorType $t) => $q->where('error_type', $t))
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('group_name', 'like', "%{$term}%")
                ->orWhere('error_message', 'like', "%{$term}%")
                ->orWhereHas('campaign', fn (Builder $c) => $c->where('title', 'like', "%{$term}%")->orWhere('message', 'like', "%{$term}%"))));
    }

    #[Computed]
    public function kpis(): array
    {
        $permanentTypes = array_map(fn (SendErrorType $t) => $t->value,
            array_filter(SendErrorType::cases(), fn (SendErrorType $t) => ! $t->isRetryable() && ! $t->pausesCampaign()));

        return [
            'failed' => CampaignGroup::where('status', SendStatus::Failed)->count(),
            'pending_retry' => CampaignGroup::whereIn('status', [SendStatus::Pending, SendStatus::Processing])->whereNotNull('error_type')->count(),
            'permanent' => CampaignGroup::where('status', SendStatus::Failed)->whereIn('error_type', $permanentTypes)->count(),
            'groups' => CampaignGroup::where('status', SendStatus::Failed)->distinct()->count('group_name'),
        ];
    }

    /** Error types that currently occur in the list, for the filter. */
    #[Computed]
    public function errorTypes(): array
    {
        return CampaignGroup::whereNotNull('error_type')->distinct()->pluck('error_type')
            ->map(fn ($t) => $t instanceof SendErrorType ? $t : SendErrorType::from($t))
            ->sortBy(fn (SendErrorType $t) => $t->label())->values()->all();
    }

    // ---- Actions -----------------------------------------------------------

    public function retry(int $id, CampaignRunner $runner): void
    {
        $count = $runner->retry(CampaignGroup::whereKey($id)->get());
        $this->done($count ? 'Queued to send again' : 'This message is no longer failed', $count ? 'success' : 'info');
    }

    public function skip(int $id, CampaignRunner $runner): void
    {
        $runner->skip(CampaignGroup::whereKey($id)->get());
        $this->done('Marked as resolved. It will not be retried.');
    }

    public function retrySelected(CampaignRunner $runner): void
    {
        $count = $runner->retry($this->selectedRows());
        $this->done("{$count} ".str('message')->plural($count).' queued to send again');
    }

    public function skipSelected(CampaignRunner $runner): void
    {
        $count = $runner->skip($this->selectedRows());
        $this->done("{$count} ".str('message')->plural($count).' marked as resolved');
    }

    private function selectedRows()
    {
        return $this->selectAll
            ? $this->query()->where('status', SendStatus::Failed)->get()
            : CampaignGroup::whereKey(array_map('intval', $this->selected))->get();
    }

    private function done(string $message, string $type = 'success'): void
    {
        $this->reset('selected', 'selectAll', 'activeId');
        unset($this->kpis, $this->active);
        $this->dispatch('close-modal', self::DRAWER);
        $this->dispatch('toast', type: $type, message: $message);
    }

    // ---- Drawer ------------------------------------------------------------

    public function openDetails(int $id): void
    {
        $row = CampaignGroup::findOrFail($id);

        $this->activeId = $row->id;
        $this->drawerAction = $row->error_type && ! $row->error_type->isRetryable() ? 'retry_after_fix' : 'retry_now';
        unset($this->active, $this->technical);
        $this->dispatch('open-modal', self::DRAWER);
    }

    public function applyDrawerAction(CampaignRunner $runner): void
    {
        if (! $this->activeId) {
            return;
        }

        $this->drawerAction === 'skip'
            ? $this->skip($this->activeId, $runner)
            : $this->retry($this->activeId, $runner);
    }

    #[Computed]
    public function active(): ?CampaignGroup
    {
        return $this->activeId
            ? CampaignGroup::with(['campaign.attachment', 'group.category'])->find($this->activeId)
            : null;
    }

    /** Technical detail of the last failed attempt, for the collapsible section. */
    #[Computed]
    public function technical(): ?string
    {
        $row = $this->active;

        return $row ? SendLog::where('campaign_id', $row->campaign_id)
            ->where('group_name', $row->group_name)
            ->whereNotNull('error_type')
            ->latest('id')
            ->value('technical_details') : null;
    }

    public function render()
    {
        $rows = $this->query()
            ->orderBy($this->sortColumn(), $this->sortDirection())
            ->orderByDesc('id')
            ->paginate($this->perPage);

        return view('livewire.failed-messages.index', ['rows' => $rows]);
    }
}

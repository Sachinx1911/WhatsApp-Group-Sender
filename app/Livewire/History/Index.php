<?php

namespace App\Livewire\History;

use App\Enums\CampaignStatus;
use App\Enums\SendStatus;
use App\Livewire\Concerns\WithTable;
use App\Models\Campaign;
use App\Models\CampaignGroup;
use App\Models\Category;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Send History (docs/MASTER_PROMPT.md §17). */
#[Title('Send History')]
class Index extends Component
{
    use WithTable;

    public const SORTABLE = ['created_at', 'total_groups', 'sent_count', 'failed_count'];

    /** Date presets. "custom" uses $from / $to. */
    public const RANGES = [
        '' => 'All time',
        'today' => 'Today',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        'custom' => 'Custom range',
    ];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $category = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $range = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(as: 'tests', except: false)]
    public bool $hideTests = false;

    #[Url(as: 'sort', except: 'created_at')]
    public string $sortBy = 'created_at';

    #[Url(as: 'dir', except: 'desc')]
    public string $sortDir = 'desc';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'category', 'status', 'range', 'from', 'to', 'hideTests'], true)) {
            $this->resetPage();
        }

        if ($property === 'range' && $this->range !== 'custom') {
            $this->reset('from', 'to');
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'category', 'status', 'range', 'from', 'to', 'hideTests');
        $this->resetPage();
    }

    /** @return array{search: string, category: string, status: string, from: ?string, to: ?string, hide_tests: bool} */
    public function filters(): array
    {
        [$from, $to] = match ($this->range) {
            'today' => [today()->toDateString(), null],
            '7d' => [today()->subDays(6)->toDateString(), null],
            '30d' => [today()->subDays(29)->toDateString(), null],
            'custom' => [$this->validDate($this->from), $this->validDate($this->to)],
            default => [null, null],
        };

        return [
            'search' => $this->search,
            'category' => $this->category,
            'status' => $this->status,
            'from' => $from,
            'to' => $to,
            'hide_tests' => $this->hideTests,
        ];
    }

    private function validDate(string $value): ?string
    {
        try {
            return $value !== '' ? Carbon::createFromFormat('Y-m-d', $value)->toDateString() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    #[Computed]
    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->category !== '' || $this->status !== '' || $this->range !== '' || $this->hideTests;
    }

    /** KPIs for the campaigns matching the current filters. */
    #[Computed]
    public function kpis(): array
    {
        $matching = Campaign::filter($this->filters());
        $totals = (clone $matching)->selectRaw('count(*) as campaigns, coalesce(sum(sent_count), 0) as sent, coalesce(sum(failed_count), 0) as failed')->first();

        return [
            'campaigns' => (int) $totals->campaigns,
            'sent' => (int) $totals->sent,
            'failed' => (int) $totals->failed,
            'groups_reached' => CampaignGroup::whereIn('campaign_id', (clone $matching)->select('id'))
                ->where('status', SendStatus::Sent)
                ->distinct()
                ->count('group_id'),
        ];
    }

    #[Computed]
    public function categories()
    {
        return Category::ordered()->get();
    }

    /** Status filter options: finished statuses plus one "In Progress" bucket. */
    public function statusOptions(): array
    {
        return [
            CampaignStatus::Completed->value => CampaignStatus::Completed->label(),
            CampaignStatus::PartiallyFailed->value => CampaignStatus::PartiallyFailed->label(),
            CampaignStatus::Failed->value => CampaignStatus::Failed->label(),
            'in_progress' => 'In Progress',
            CampaignStatus::Scheduled->value => CampaignStatus::Scheduled->label(),
            CampaignStatus::Cancelled->value => CampaignStatus::Cancelled->label(),
        ];
    }

    public function render()
    {
        $campaigns = Campaign::query()
            ->filter($this->filters())
            ->orderBy($this->sortColumn(), $this->sortDirection())
            ->orderByDesc('id')
            ->paginate($this->perPage);

        return view('livewire.history.index', ['campaigns' => $campaigns]);
    }
}

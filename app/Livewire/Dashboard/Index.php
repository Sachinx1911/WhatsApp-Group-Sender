<?php

namespace App\Livewire\Dashboard;

use App\Enums\CampaignStatus;
use App\Enums\GroupStatus;
use App\Enums\SendStatus;
use App\Models\Campaign;
use App\Models\CampaignGroup;
use App\Models\Group;
use App\Models\WhatsAppSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Dashboard')]
class Index extends Component
{
    public const RECENT_CAMPAIGNS = 6;

    /** Hours always shown on the activity chart (6 AM – 10 PM); widened if there was activity outside. */
    private const CHART_FIRST_HOUR = 6;

    private const CHART_LAST_HOUR = 22;

    /** Called by wire:poll; re-renders with fresh numbers and pushes new chart data to the browser. */
    public function refresh(): void
    {
        $this->dispatch('activity-updated', chart: $this->chart);
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function stats(): array
    {
        $groups = Group::query()
            ->selectRaw('count(*) as total, sum(case when status = ? then 1 else 0 end) as active', [GroupStatus::Active->value])
            ->first();

        $sentOn = fn (Carbon $day) => CampaignGroup::where('status', SendStatus::Sent)->whereDate('sent_at', $day)->count();

        return [
            'groups_total' => (int) $groups->total,
            'groups_active' => (int) $groups->active,
            'sent_today' => $sentOn(today()),
            'sent_yesterday' => $sentOn(today()->subDay()),
            'pending' => CampaignGroup::whereIn('status', [SendStatus::Pending, SendStatus::Processing])->count(),
            'active_campaigns' => Campaign::whereIn('status', [CampaignStatus::Queued, CampaignStatus::Sending, CampaignStatus::Paused])->count(),
            'failed' => CampaignGroup::where('status', SendStatus::Failed)->count(),
            'session' => WhatsAppSession::current(),
        ];
    }

    /** @return array{labels: array<int, string>, series: array<int, array{name: string, data: array<int, int>}>, totals: array{sent: int, failed: int}} */
    #[Computed]
    public function chart(): array
    {
        $sentHours = $this->hoursOf(CampaignGroup::where('status', SendStatus::Sent)->whereDate('sent_at', today())->pluck('sent_at'));
        $failedHours = $this->hoursOf(CampaignGroup::where('status', SendStatus::Failed)->whereDate('updated_at', today())->pluck('updated_at'));

        $active = $sentHours->keys()->merge($failedHours->keys());
        $first = min(self::CHART_FIRST_HOUR, $active->min() ?? self::CHART_FIRST_HOUR);
        $last = max(self::CHART_LAST_HOUR, $active->max() ?? self::CHART_LAST_HOUR);
        $hours = range($first, $last);

        return [
            'labels' => array_map(fn (int $h) => Carbon::today()->setHour($h)->format('g A'), $hours),
            'series' => [
                ['name' => 'Sent', 'data' => array_map(fn (int $h) => $sentHours->get($h, 0), $hours)],
                ['name' => 'Failed', 'data' => array_map(fn (int $h) => $failedHours->get($h, 0), $hours)],
            ],
            'totals' => ['sent' => $sentHours->sum(), 'failed' => $failedHours->sum()],
        ];
    }

    #[Computed]
    public function recentCampaigns()
    {
        return Campaign::latest()->limit(self::RECENT_CAMPAIGNS)->get();
    }

    /** @return Collection<int, int> hour => count */
    private function hoursOf(Collection $timestamps): Collection
    {
        return $timestamps->countBy(fn ($time) => Carbon::parse($time)->hour);
    }

    public function render()
    {
        return view('livewire.dashboard.index');
    }
}

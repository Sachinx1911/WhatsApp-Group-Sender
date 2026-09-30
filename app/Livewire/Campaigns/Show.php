<?php

namespace App\Livewire\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\SendErrorType;
use App\Enums\SendStatus;
use App\Jobs\StartCampaignJob;
use App\Models\Campaign;
use App\Models\SendLog;
use App\Services\Campaigns\CampaignRunner;
use App\Support\SendEstimate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;

/** Live sending progress and campaign details (docs/MASTER_PROMPT.md §11). */
class Show extends Component
{
    use WithoutUrlPagination, WithPagination;

    public const CANCEL_MODAL = 'confirm-cancel-campaign';

    public const PER_PAGE = 25;

    public Campaign $campaign;

    /** '' (all), 'pending', 'sent', 'failed' */
    public string $filter = '';

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function pause(CampaignRunner $runner): void
    {
        $runner->pause($this->campaign);
        $this->dispatch('toast', type: 'info', message: 'Paused. The group being sent right now will finish first.');
    }

    public function resume(CampaignRunner $runner): void
    {
        $runner->resume($this->campaign);
        $this->campaign->refresh();

        $this->dispatch('toast', type: 'success', message: $this->campaign->status === CampaignStatus::Queued
            ? 'Resumed. It will continue after the campaign that is sending now.'
            : 'Sending resumed');
    }

    public function cancel(CampaignRunner $runner): void
    {
        $wasScheduled = $this->campaign->status === CampaignStatus::Scheduled;
        $runner->cancel($this->campaign);

        $this->dispatch('close-modal', self::CANCEL_MODAL);
        $this->dispatch('toast', type: 'success', message: $wasScheduled
            ? 'Scheduled message cancelled. Nothing was sent.'
            : 'Campaign cancelled. Groups that were not reached will not receive it.');
    }

    /** New date-time for a scheduled campaign, as <input type="datetime-local"> gives it. */
    public string $newTime = '';

    /** Send a scheduled campaign right away instead of waiting. */
    public function sendNow(CampaignRunner $runner): void
    {
        $runner->release($this->campaign);
        $this->campaign->refresh();

        $this->dispatch('toast', type: 'success', message: $this->campaign->status === CampaignStatus::Queued
            ? 'Queued. It starts after the campaign that is sending now.'
            : 'Sending started');
    }

    public function reschedule(CampaignRunner $runner): void
    {
        $at = $this->newTime !== '' ? CarbonImmutable::createFromFormat('Y-m-d\TH:i', $this->newTime) : null;

        if (! $at || $at->lte(now()->addMinute())) {
            $this->addError('newTime', 'Choose a time at least a few minutes from now.');

            return;
        }

        if ($at->gt(now()->addDays(60))) {
            $this->addError('newTime', 'Schedule at most 60 days ahead.');

            return;
        }

        $runner->reschedule($this->campaign, $at);
        $this->campaign->refresh();
        $this->reset('newTime');

        $this->dispatch('toast', type: 'success', message: 'Rescheduled for '.$this->campaign->scheduled_at->format('D, d M \a\t g:i A').'.');
    }

    /** Send every failed group of this campaign again (it waits its turn if another campaign is sending). */
    public function retryFailed(CampaignRunner $runner): void
    {
        $count = $runner->retry($this->campaign->campaignGroups()->where('status', SendStatus::Failed)->get());
        $this->campaign->refresh();

        $this->dispatch('toast', type: 'success', message: "{$count} failed ".str('group')->plural($count).' queued to send again');
    }

    /** "Sending" but nothing happened for a while: queue the pending groups again (duplicates are skipped). */
    public function restart(): void
    {
        if ($this->campaign->status === CampaignStatus::Sending) {
            StartCampaignJob::dispatch($this->campaign->id);
            $this->dispatch('toast', type: 'info', message: 'Pending groups queued again.');
        }
    }

    #[Computed]
    public function counts(): array
    {
        $counts = $this->campaign->campaignGroups()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $get = fn (SendStatus $s) => (int) ($counts[$s->value] ?? 0);

        return [
            'sent' => $get(SendStatus::Sent),
            'processing' => $get(SendStatus::Processing),
            'pending' => $get(SendStatus::Pending),
            'failed' => $get(SendStatus::Failed),
            'skipped' => $get(SendStatus::Skipped),
            'cancelled' => $get(SendStatus::Cancelled),
        ];
    }

    /** The sender has not touched this campaign for a while although groups are waiting. */
    #[Computed]
    public function stalled(): bool
    {
        if ($this->campaign->status !== CampaignStatus::Sending) {
            return false;
        }

        // "Sending" with nothing left to send should have been closed; offer the restart,
        // which finishes it (StartCampaignJob closes a campaign with no pending rows).
        if ($this->counts['pending'] + $this->counts['processing'] === 0) {
            return true;
        }

        $lastActivity = $this->campaign->campaignGroups()->max('updated_at') ?? $this->campaign->started_at;
        $allowance = max(90, SendEstimate::secondsPerGroup() * 3);

        return $lastActivity !== null && now()->diffInSeconds($lastActivity, true) > $allowance;
    }

    /** Why the campaign is paused, when it was paused automatically. */
    #[Computed]
    public function pauseReason(): ?SendErrorType
    {
        if ($this->campaign->status !== CampaignStatus::Paused) {
            return null;
        }

        return SendLog::where('campaign_id', $this->campaign->id)
            ->whereIn('error_type', array_map(fn ($t) => $t->value, array_filter(SendErrorType::cases(), fn ($t) => $t->pausesCampaign())))
            ->when($this->campaign->paused_at, fn ($q) => $q->where('created_at', '>=', $this->campaign->paused_at->copy()->subMinute()))
            ->latest('id')
            ->value('error_type');
    }

    /** @return array<int, array{label: string, at: Carbon, tone: string}> key moments, oldest first */
    #[Computed]
    public function timeline(): array
    {
        $c = $this->campaign;
        $sent = $c->campaignGroups()->where('status', SendStatus::Sent)->selectRaw('min(sent_at) as first, max(sent_at) as last, count(*) as n')->first();

        $events = [
            ['label' => 'Campaign created', 'at' => $c->created_at, 'tone' => 'muted'],
            ['label' => 'Sending started', 'at' => $c->started_at, 'tone' => 'primary'],
            ['label' => 'First group received it', 'at' => $sent?->first ? Carbon::parse($sent->first) : null, 'tone' => 'success'],
            ['label' => 'Paused', 'at' => $c->status === CampaignStatus::Paused ? $c->paused_at : null, 'tone' => 'warning'],
            ['label' => 'Last group received it', 'at' => $c->status->isFinished() && $sent?->n > 1 ? Carbon::parse($sent->last) : null, 'tone' => 'success'],
            ['label' => $c->status->label(), 'at' => $c->status->isFinished() ? $c->completed_at : null, 'tone' => $c->status->color()],
        ];

        return array_values(array_filter($events, fn ($e) => $e['at'] !== null));
    }

    #[Computed]
    public function sendingNow(): ?Campaign
    {
        return $this->campaign->status === CampaignStatus::Queued
            ? Campaign::whereIn('status', [CampaignStatus::Sending, CampaignStatus::Paused])->first()
            : null;
    }

    public function render()
    {
        $this->campaign->refresh();

        $rows = $this->campaign->campaignGroups()
            ->with('group:id,member_count')
            ->when($this->filter === 'pending', fn ($q) => $q->whereIn('status', [SendStatus::Pending, SendStatus::Processing]))
            ->when($this->filter === 'sent', fn ($q) => $q->where('status', SendStatus::Sent))
            ->when($this->filter === 'failed', fn ($q) => $q->whereIn('status', [SendStatus::Failed, SendStatus::Skipped, SendStatus::Cancelled]))
            ->orderByRaw('case status when ? then 0 else 1 end', [SendStatus::Processing->value])
            ->orderBy('id')
            ->paginate(self::PER_PAGE, pageName: 'rowsPage');

        return view('livewire.campaigns.show', ['rows' => $rows])
            ->title($this->campaign->isActive() ? 'Sending Message' : $this->campaign->title);
    }
}

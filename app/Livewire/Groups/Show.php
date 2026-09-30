<?php

namespace App\Livewire\Groups;

use App\Enums\GroupStatus;
use App\Enums\SendStatus;
use App\Models\Group;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/** Group details (docs/MASTER_PROMPT.md §14). */
class Show extends Component
{
    public const RECENT_SENDS = 10;

    public Group $group;

    #[On('group-saved')]
    public function refreshGroup(): void
    {
        $this->group->refresh();
        unset($this->stats, $this->recentSends);
    }

    public function toggleStatus(): void
    {
        $this->group->update(['status' => $this->group->isActive() ? GroupStatus::Inactive : GroupStatus::Active]);

        $this->dispatch('toast', type: 'success', message: "“{$this->group->name}” is now {$this->group->status->label()}");
    }

    #[Computed]
    public function stats(): array
    {
        $counts = $this->group->campaignGroups()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'sent' => (int) ($counts[SendStatus::Sent->value] ?? 0),
            'failed' => (int) ($counts[SendStatus::Failed->value] ?? 0),
            'total' => (int) $counts->sum(),
        ];
    }

    #[Computed]
    public function recentSends()
    {
        return $this->group->campaignGroups()
            ->with('campaign:id,title,message,attachment_name,is_test,created_at')
            ->latest('id')
            ->limit(self::RECENT_SENDS)
            ->get();
    }

    public function render()
    {
        return view('livewire.groups.show')->title($this->group->name);
    }
}

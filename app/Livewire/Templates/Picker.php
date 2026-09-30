<?php

namespace App\Livewire\Templates;

use App\Models\MessageTemplate;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;

/** "Insert Template" modal. Open with 'pick-template'; the choice comes back as 'template-picked'. */
class Picker extends Component
{
    use WithoutUrlPagination, WithPagination;

    public const MODAL = 'template-picker';

    public const PER_PAGE = 8;

    public string $search = '';

    #[On('pick-template')]
    public function open(): void
    {
        $this->reset('search');
        $this->resetPage();
        $this->dispatch('open-modal', self::MODAL);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function choose(int $id): void
    {
        $this->dispatch('template-picked', id: MessageTemplate::findOrFail($id)->id);
        $this->dispatch('close-modal', self::MODAL);
    }

    public function render()
    {
        $templates = MessageTemplate::with(['category', 'attachment'])
            ->filter(['search' => $this->search])
            ->orderByDesc('last_used_at')
            ->orderBy('title')
            ->paginate(self::PER_PAGE, pageName: 'templatesPage');

        return view('livewire.templates.picker', ['templates' => $templates]);
    }
}

<?php

namespace App\Livewire\Media;

use App\Enums\MediaType;
use App\Models\Media;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;

/**
 * "Choose from Media Library" modal. Open with the 'pick-media' event and a context
 * name; the choice comes back as 'media-picked' with the same context.
 */
class Picker extends Component
{
    use WithoutUrlPagination, WithPagination;

    public const MODAL = 'media-picker';

    public const PER_PAGE = 12;

    public string $context = '';

    public string $search = '';

    public string $type = '';

    #[On('pick-media')]
    public function open(string $context, string $type = ''): void
    {
        $this->context = $context;
        $this->reset('search');
        $this->type = MediaType::tryFrom($type)?->value ?? '';
        $this->resetPage();
        $this->dispatch('open-modal', self::MODAL);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'type'], true)) {
            $this->resetPage();
        }
    }

    public function choose(int $id): void
    {
        $media = Media::findOrFail($id);

        $this->dispatch('media-picked', id: $media->id, context: $this->context);
        $this->dispatch('close-modal', self::MODAL);
    }

    public function render()
    {
        $media = Media::query()
            ->when(trim($this->search) !== '', fn ($q) => $q->where('original_name', 'like', '%'.trim($this->search).'%'))
            ->when(MediaType::tryFrom($this->type), fn ($q, MediaType $type) => $q->where('type', $type))
            ->latest()
            ->paginate(self::PER_PAGE, pageName: 'mediaPage');

        return view('livewire.media.picker', ['items' => $media]);
    }
}

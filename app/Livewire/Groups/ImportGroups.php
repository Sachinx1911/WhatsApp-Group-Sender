<?php

namespace App\Livewire\Groups;

use App\Actions\Groups\GroupCsv;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

/** CSV import with a preview step: nothing is saved until the admin confirms. */
class ImportGroups extends Component
{
    use WithFileUploads;

    public const MODAL = 'import-groups';

    #[Validate('required|file|mimes:csv,txt|max:1024', as: 'CSV file')]
    public $file;

    public array $rows = [];

    public ?string $fileName = null;

    public bool $createCategories = true;

    public bool $updateExisting = false;

    public bool $problemsOnly = false;

    #[On('import-groups')]
    public function start(): void
    {
        $this->reset();
        $this->resetValidation();
        $this->dispatch('open-modal', self::MODAL);
    }

    public function updatedFile(): void
    {
        $this->validateOnly('file');
        $this->rows = [];

        try {
            $this->rows = app(GroupCsv::class)->parse($this->file->getRealPath());
            $this->fileName = $this->file->getClientOriginalName();
        } catch (InvalidArgumentException $e) {
            $this->addError('file', $e->getMessage());
        }

        $this->reset('file');
    }

    #[Computed]
    public function counts(): array
    {
        $rows = collect($this->rows);

        return [
            'total' => $rows->count(),
            'new' => $rows->where('state', 'new')->count(),
            'existing' => $rows->where('state', 'existing')->count(),
            'invalid' => $rows->where('state', 'invalid')->count(),
            'missing_categories' => $rows->where('state', '!=', 'invalid')->where('category_missing', true)->pluck('category')->unique()->values()->all(),
        ];
    }

    /** Rows that will actually be saved with the current options. */
    #[Computed]
    public function importable(): int
    {
        return collect($this->rows)
            ->reject(fn ($r) => $r['state'] === 'invalid')
            ->reject(fn ($r) => $r['state'] === 'existing' && ! $this->updateExisting)
            ->reject(fn ($r) => $r['category_missing'] && ! $this->createCategories)
            ->count();
    }

    public function import(): void
    {
        if ($this->importable === 0) {
            return;
        }

        $summary = app(GroupCsv::class)->import($this->rows, $this->createCategories, $this->updateExisting);

        $parts = array_filter([
            $summary['created'] ? "{$summary['created']} added" : null,
            $summary['updated'] ? "{$summary['updated']} updated" : null,
            $summary['skipped'] ? "{$summary['skipped']} skipped" : null,
            $summary['categories_created'] ? "{$summary['categories_created']} new ".str('category')->plural($summary['categories_created']) : null,
        ]);

        $this->dispatch('close-modal', self::MODAL);
        $this->dispatch('group-saved');
        $this->dispatch('categories-changed');
        $this->dispatch('toast', type: 'success', message: 'Import complete: '.implode(', ', $parts));
        $this->reset();
    }

    public function startOver(): void
    {
        $this->reset('rows', 'fileName', 'file', 'problemsOnly');
        $this->resetValidation();
    }

    public function render()
    {
        return view('livewire.groups.import-groups');
    }
}

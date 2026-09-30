<?php

namespace App\Livewire\Concerns;

use App\Support\Pagination;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Shared table behaviour: database pagination with 25/50/100 rows per page
 * (docs/MASTER_PROMPT.md §44) and whitelisted column sorting.
 *
 * Components define SORTABLE (allowed columns) and may override $sortBy/$sortDir defaults.
 */
trait WithTable
{
    use WithPagination;

    #[Url(as: 'per_page', except: 25)]
    public int $perPage = 25;

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, Pagination::PER_PAGE_OPTIONS, true)) {
            $this->perPage = Pagination::PER_PAGE_OPTIONS[0];
        }

        $this->resetPage();
    }

    public function sortBy(string $column): void
    {
        if (! in_array($column, static::SORTABLE, true)) {
            return;
        }

        $this->sortDir = $this->sortBy === $column && $this->sortDir === 'asc' ? 'desc' : 'asc';
        $this->sortBy = $column;
        $this->resetPage();
    }

    protected function sortColumn(): string
    {
        return in_array($this->sortBy, static::SORTABLE, true) ? $this->sortBy : static::SORTABLE[0];
    }

    protected function sortDirection(): string
    {
        return $this->sortDir === 'desc' ? 'desc' : 'asc';
    }

    public function paginationView(): string
    {
        return 'livewire.partials.pagination';
    }
}

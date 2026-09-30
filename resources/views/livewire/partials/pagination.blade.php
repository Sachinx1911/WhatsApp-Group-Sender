{{-- Table footer: "Showing x–y of z", rows per page and page links. Used via App\Livewire\Concerns\WithTable. --}}
@php
    $pageName = $paginator->getPageName();
    $scroll = "(\$el.closest('section') || document.body).scrollIntoView({ behavior: 'smooth', block: 'start' })";
    $btn = 'grid h-8 min-w-8 place-items-center rounded-lg px-2 text-[13px] transition';
@endphp

<div class="flex flex-wrap items-center justify-between gap-3 border-t border-line px-5 py-3 text-[13px] text-muted">
    <div class="flex items-center gap-3">
        <span>
            @if ($paginator->total())
                Showing <b class="font-medium text-ink">{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }}</b>
                of <b class="font-medium text-ink">{{ number_format($paginator->total()) }}</b>
            @else
                No results
            @endif
        </span>
        <label class="flex items-center gap-2">
            <span class="hidden sm:inline">Rows</span>
            <select wire:model.live="perPage" class="rounded-lg border border-line bg-white py-1 pl-2 pr-7 text-[13px] text-ink outline-none focus:border-primary">
                @foreach (\App\Support\Pagination::PER_PAGE_OPTIONS as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </select>
        </label>
    </div>

    @if ($paginator->hasPages())
        <nav class="flex items-center gap-1" aria-label="Pagination">
            <button type="button" wire:click="previousPage('{{ $pageName }}')" x-on:click="{{ $scroll }}"
                @disabled($paginator->onFirstPage()) aria-label="Previous page"
                class="{{ $btn }} text-ink hover:bg-canvas disabled:cursor-default disabled:text-slate-300 disabled:hover:bg-transparent">
                <x-lucide-chevron-left class="size-4" />
            </button>

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="{{ $btn }}">…</span>
                @else
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="{{ $btn }} bg-primary font-medium text-white" aria-current="page">{{ $page }}</span>
                        @else
                            <button type="button" wire:click="gotoPage({{ $page }}, '{{ $pageName }}')" x-on:click="{{ $scroll }}"
                                class="{{ $btn }} text-ink hover:bg-canvas">{{ $page }}</button>
                        @endif
                    @endforeach
                @endif
            @endforeach

            <button type="button" wire:click="nextPage('{{ $pageName }}')" x-on:click="{{ $scroll }}"
                @disabled(! $paginator->hasMorePages()) aria-label="Next page"
                class="{{ $btn }} text-ink hover:bg-canvas disabled:cursor-default disabled:text-slate-300 disabled:hover:bg-transparent">
                <x-lucide-chevron-right class="size-4" />
            </button>
        </nav>
    @endif
</div>

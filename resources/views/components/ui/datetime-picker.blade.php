{{--
    Date + time picker for scheduling: a Date field opening a month calendar and a Time
    field opening a list of slots. Writes "YYYY-MM-DDTHH:mm" (the datetime-local format
    the backend already accepts) to the Livewire property named in `model`.

    <x-ui.datetime-picker model="form.scheduledFor" />
--}}
@props([
    'model',
    'minutesStep' => 15,
    'maxDays' => 60,
])

<div x-data="datetimePicker({ value: $wire.$entangle(@js($model)), step: {{ (int) $minutesStep }}, maxDays: {{ (int) $maxDays }} })"
    x-on:keydown.escape.stop="closeAll()" x-on:click.outside="closeAll()"
    {{ $attributes->class('space-y-2') }}>

    {{-- Date field --}}
    <div class="relative">
        <button type="button" x-on:click="toggle('date')"
            class="flex w-full items-center gap-3 rounded-xl border bg-white px-3.5 py-2 text-left outline-none transition focus:ring-4 focus:ring-primary/20"
            :class="open === 'date' ? 'border-primary ring-4 ring-primary/20' : 'border-line hover:border-slate-300'">
            <x-lucide-calendar class="size-4 shrink-0 text-muted" />
            <span class="min-w-0 flex-1">
                <span class="block text-[11px] leading-tight text-muted">Date</span>
                <span class="block truncate text-sm font-medium" :class="date ? 'text-ink' : 'text-muted/70'" x-text="date ? dateLabel(date) : 'Choose a date'"></span>
            </span>
            <x-lucide-chevron-down class="size-4 shrink-0 text-muted transition" ::class="open === 'date' ? 'rotate-180' : ''" />
        </button>

        <div x-show="open === 'date'" x-cloak x-transition.origin.top
            class="absolute left-0 top-full z-30 mt-1.5 w-[300px] rounded-2xl border border-line bg-white p-3 shadow-xl">
            <div class="mb-2 flex items-center justify-between">
                <button type="button" x-on:click="prevMonth()" :disabled="!canGoPrev()" class="rounded-lg p-1.5 text-muted transition hover:bg-canvas hover:text-ink disabled:opacity-30" aria-label="Previous month">
                    <x-lucide-chevron-left class="size-4" />
                </button>
                <span class="text-sm font-semibold" x-text="monthLabel()"></span>
                <button type="button" x-on:click="nextMonth()" :disabled="!canGoNext()" class="rounded-lg p-1.5 text-muted transition hover:bg-canvas hover:text-ink disabled:opacity-30" aria-label="Next month">
                    <x-lucide-chevron-right class="size-4" />
                </button>
            </div>
            <div class="mb-1 grid grid-cols-7 text-center text-[11px] font-medium text-muted">
                <template x-for="d in ['Su','Mo','Tu','We','Th','Fr','Sa']"><span x-text="d" class="py-1"></span></template>
            </div>
            <div class="grid grid-cols-7 gap-y-0.5 text-center text-[13px]">
                <template x-for="(cell, i) in cells()" :key="i">
                    <div class="flex justify-center py-0.5">
                        <button type="button" x-show="cell" x-on:click="pickDate(cell)" :disabled="cell && cell.disabled"
                            class="size-8 rounded-full font-medium transition"
                            :class="cell && cell.iso === date
                                ? 'bg-primary text-white shadow-sm'
                                : (cell && cell.disabled ? 'text-slate-300 cursor-not-allowed' : (cell && cell.today ? 'text-primary ring-1 ring-primary/40 hover:bg-primary-soft' : 'text-ink hover:bg-canvas'))"
                            x-text="cell ? cell.day : ''"></button>
                    </div>
                </template>
            </div>
            <div class="mt-2 flex items-center justify-between border-t border-line pt-2">
                <button type="button" x-on:click="pickDate({ iso: todayIso(), disabled: false })" class="rounded-lg px-2.5 py-1 text-xs font-medium text-primary transition hover:bg-primary-soft">Today</button>
                <button type="button" x-on:click="clear()" class="rounded-lg bg-canvas px-2.5 py-1 text-xs font-medium text-muted transition hover:text-ink">Clear</button>
            </div>
        </div>
    </div>

    {{-- Time field --}}
    <div class="relative">
        <button type="button" x-on:click="toggle('time')"
            class="flex w-full items-center gap-3 rounded-xl border bg-white px-3.5 py-2 text-left outline-none transition focus:ring-4 focus:ring-primary/20"
            :class="open === 'time' ? 'border-primary ring-4 ring-primary/20' : 'border-line hover:border-slate-300'">
            <x-lucide-clock class="size-4 shrink-0 text-muted" />
            <span class="min-w-0 flex-1">
                <span class="block text-[11px] leading-tight text-muted">Time</span>
                <span class="block truncate text-sm font-medium" :class="time ? 'text-ink' : 'text-muted/70'" x-text="time ? timeLabel(time) : 'Choose a time'"></span>
            </span>
            <x-lucide-chevron-down class="size-4 shrink-0 text-muted transition" ::class="open === 'time' ? 'rotate-180' : ''" />
        </button>

        <div x-show="open === 'time'" x-cloak x-transition.origin.top
            class="absolute left-0 top-full z-30 mt-1.5 w-full rounded-2xl border border-line bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-line px-3.5 py-2.5">
                <span class="text-sm font-semibold" x-text="time ? timeLabel(time) : 'Pick a time'"></span>
                <button type="button" x-on:click="open = null" class="rounded-lg p-1 text-muted hover:bg-canvas hover:text-ink" aria-label="Close"><x-lucide-x class="size-4" /></button>
            </div>
            <ul x-ref="slots" class="max-h-56 overflow-y-auto py-1.5">
                <template x-for="slot in slots()" :key="slot.value">
                    <li>
                        <button type="button" x-on:click="pickTime(slot.value)" :disabled="slot.disabled" :data-slot="slot.value"
                            class="flex w-full items-center gap-3 px-3.5 py-1.5 text-left text-[13px] transition"
                            :class="slot.value === time ? 'bg-primary-soft font-semibold text-primary' : (slot.disabled ? 'text-slate-300 cursor-not-allowed' : 'text-ink hover:bg-canvas')">
                            <span class="w-12 tabular-nums" x-text="slot.hm"></span>
                            <span class="text-muted" x-text="slot.ampm"></span>
                        </button>
                    </li>
                </template>
            </ul>
        </div>
    </div>

    <p x-show="date && time" x-cloak class="text-xs text-muted">
        Will send on <span class="font-medium text-ink" x-text="date ? dateLabel(date) : ''"></span> at <span class="font-medium text-ink" x-text="time ? timeLabel(time) : ''"></span>.
    </p>
</div>


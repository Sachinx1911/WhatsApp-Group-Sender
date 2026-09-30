<div>
    <x-ui.modal :name="\App\Livewire\Groups\ImportGroups::MODAL" title="Import Groups" max-width="max-w-3xl">
        @if (! $rows)
            {{-- Step 1: choose a file --}}
            <div class="space-y-4">
                <label x-data="{ dragging: false }"
                    x-on:dragover.prevent="dragging = true" x-on:dragleave.prevent="dragging = false"
                    x-on:drop.prevent="dragging = false; $refs.input.files = $event.dataTransfer.files; $refs.input.dispatchEvent(new Event('change'))"
                    :class="dragging ? 'border-primary bg-primary-soft' : 'border-line bg-canvas'"
                    class="flex cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed px-6 py-10 text-center transition hover:border-primary/50">
                    <span class="grid size-12 place-items-center rounded-2xl bg-white text-primary shadow-card">
                        <x-lucide-upload wire:loading.remove wire:target="file" class="size-5" />
                        <x-lucide-loader-circle wire:loading wire:target="file" class="size-5 animate-spin" />
                    </span>
                    <span class="mt-3 text-sm font-medium">Drag & drop a CSV file here, or <span class="text-primary">browse</span></span>
                    <span class="mt-1 text-xs text-muted">UTF-8 CSV · up to {{ \App\Actions\Groups\GroupCsv::MAX_ROWS }} groups · max 1 MB</span>
                    <input x-ref="input" wire:model="file" type="file" accept=".csv,text/csv" class="sr-only">
                </label>

                @error('file')
                    <div role="alert" class="flex items-start gap-2.5 rounded-xl border border-danger/20 bg-danger-soft px-3.5 py-3 text-[13px] text-danger">
                        <x-lucide-circle-alert class="mt-px size-4 shrink-0" /> {{ $message }}
                    </div>
                @enderror

                <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-line px-4 py-3 text-[13px]">
                    <div>
                        <p class="font-medium">Columns: <code class="text-xs">name, category, member_count, status</code></p>
                        <p class="text-xs text-muted">Only <b>name</b> is required. Names must match WhatsApp exactly. Status is “active” or “inactive”.</p>
                    </div>
                    <x-ui.button :href="route('groups.import.sample')" variant="secondary" size="sm" icon="download">Download sample CSV</x-ui.button>
                </div>
            </div>
        @else
            {{-- Step 2: preview --}}
            @php $counts = $this->counts; @endphp
            <div class="space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-2 text-[13px]">
                    <p class="flex items-center gap-2 font-medium"><x-lucide-file-spreadsheet class="size-4 text-success" /> {{ $fileName }}</p>
                    <button type="button" wire:click="startOver" class="text-primary hover:underline">Choose another file</button>
                </div>

                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    @foreach ([
                        ['Rows', $counts['total'], 'text-ink'],
                        ['New groups', $counts['new'], 'text-emerald-700'],
                        ['Already exist', $counts['existing'], 'text-blue-700'],
                        ['Invalid', $counts['invalid'], $counts['invalid'] ? 'text-red-600' : 'text-ink'],
                    ] as [$label, $value, $tone])
                        <div class="rounded-xl bg-canvas px-4 py-3">
                            <p class="text-xs text-muted">{{ $label }}</p>
                            <p class="text-xl font-semibold {{ $tone }}">{{ $value }}</p>
                        </div>
                    @endforeach
                </div>

                <div class="space-y-2 rounded-xl border border-line p-4 text-[13px]">
                    @if ($counts['missing_categories'])
                        <label class="flex items-start gap-2.5">
                            <input wire:model.live="createCategories" type="checkbox" class="mt-0.5 size-4 accent-primary">
                            <span>
                                Create {{ count($counts['missing_categories']) }} new {{ Str::plural('category', count($counts['missing_categories'])) }}:
                                <b>{{ implode(', ', $counts['missing_categories']) }}</b>
                                <span class="block text-xs text-muted">If unchecked, groups in these categories are skipped.</span>
                            </span>
                        </label>
                    @endif
                    @if ($counts['existing'])
                        <label class="flex items-start gap-2.5">
                            <input wire:model.live="updateExisting" type="checkbox" class="mt-0.5 size-4 accent-primary">
                            <span>
                                Update the {{ $counts['existing'] }} {{ Str::plural('group', $counts['existing']) }} that already exist
                                <span class="block text-xs text-muted">Category, members and status are replaced with the values from the file. If unchecked, they are skipped.</span>
                            </span>
                        </label>
                    @endif
                    @if (! $counts['missing_categories'] && ! $counts['existing'])
                        <p class="flex items-center gap-2 text-muted"><x-lucide-circle-check class="size-4 text-success" /> All categories exist and all groups are new.</p>
                    @endif
                </div>

                <div class="flex items-center justify-between">
                    <p class="text-[13px] font-medium">Preview</p>
                    @if ($counts['invalid'])
                        <label class="flex items-center gap-2 text-xs text-muted">
                            <input wire:model.live="problemsOnly" type="checkbox" class="size-3.5 accent-primary"> Show invalid rows only
                        </label>
                    @endif
                </div>

                <div class="max-h-72 overflow-auto rounded-xl border border-line">
                    <table class="w-full text-left text-[13px]">
                        <thead class="sticky top-0 bg-canvas text-xs text-muted">
                            <tr>
                                <th class="px-3 py-2 font-medium">Line</th>
                                <th class="px-3 py-2 font-medium">Name</th>
                                <th class="px-3 py-2 font-medium">Category</th>
                                <th class="px-3 py-2 text-right font-medium">Members</th>
                                <th class="px-3 py-2 font-medium">Status</th>
                                <th class="px-3 py-2 font-medium">Result</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($rows as $row)
                                @continue($problemsOnly && $row['state'] !== 'invalid')
                                <tr wire:key="row-{{ $row['line'] }}" @class(['bg-danger-soft/60' => $row['state'] === 'invalid'])>
                                    <td class="px-3 py-2 text-muted">{{ $row['line'] }}</td>
                                    <td class="max-w-[200px] truncate px-3 py-2 font-medium">{{ $row['name'] ?: '—' }}</td>
                                    <td class="px-3 py-2">
                                        {{ $row['category'] }}
                                        @if ($row['category_missing'] && $row['state'] !== 'invalid')
                                            <x-ui.badge color="warning" class="ml-1">New</x-ui.badge>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ $row['member_count'] ?? '—' }}</td>
                                    <td class="px-3 py-2">{{ ucfirst($row['status']) }}</td>
                                    <td class="px-3 py-2">
                                        @if ($row['state'] === 'invalid')
                                            <span class="text-xs text-red-700">{{ implode('; ', $row['errors']) }}</span>
                                        @elseif ($row['state'] === 'existing')
                                            <x-ui.badge color="primary">{{ $updateExisting ? 'Will update' : 'Exists — skip' }}</x-ui.badge>
                                        @elseif ($row['category_missing'] && ! $createCategories)
                                            <x-ui.badge color="muted">Skip</x-ui.badge>
                                        @else
                                            <x-ui.badge color="success">New</x-ui.badge>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
            @if ($rows)
                <x-ui.button wire:click="import" icon="upload" wire:loading.attr="disabled" wire:target="import" :disabled="$this->importable === 0">
                    <span wire:loading.remove wire:target="import">Import {{ $this->importable }} {{ Str::plural('group', $this->importable) }}</span>
                    <span wire:loading wire:target="import">Importing...</span>
                </x-ui.button>
            @endif
        </x-slot:footer>
    </x-ui.modal>
</div>

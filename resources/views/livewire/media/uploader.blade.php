<div>
    <x-ui.modal :name="\App\Livewire\Media\Uploader::MODAL" title="Upload Media" max-width="max-w-xl">
        <div x-data="{ dragging: false, uploading: false, progress: 0 }"
            x-on:livewire-upload-start="uploading = true; progress = 0"
            x-on:livewire-upload-progress="progress = $event.detail.progress"
            x-on:livewire-upload-finish="uploading = false"
            x-on:livewire-upload-error="uploading = false"
            x-on:livewire-upload-cancel="uploading = false">

            <label x-on:dragover.prevent="dragging = true" x-on:dragleave.prevent="dragging = false"
                x-on:drop.prevent="dragging = false; $refs.input.files = $event.dataTransfer.files; $refs.input.dispatchEvent(new Event('change'))"
                :class="dragging ? 'border-primary bg-primary-soft' : 'border-line bg-canvas'"
                class="flex cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed px-6 py-10 text-center transition hover:border-primary/50">
                <span class="grid size-12 place-items-center rounded-2xl bg-white text-primary shadow-card">
                    <x-lucide-upload x-show="!uploading" class="size-5" />
                    <x-lucide-loader-circle x-show="uploading" x-cloak class="size-5 animate-spin" />
                </span>
                <span class="mt-3 text-sm font-medium">Drag & drop images or PDFs here, or <span class="text-primary">browse</span></span>
                <span class="mt-1 text-xs text-muted">
                    JPG, JPEG, PNG up to {{ config('educationhub.media.max_image_mb') }} MB · PDF up to {{ config('educationhub.media.max_pdf_mb') }} MB ·
                    {{ config('educationhub.media.max_files_per_upload') }} files at a time
                </span>
                <input x-ref="input" wire:model="uploads" type="file" multiple accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" class="sr-only">
            </label>

            {{-- Transfer progress (browser → app) --}}
            <div x-show="uploading" x-cloak class="mt-4">
                <div class="mb-1 flex justify-between text-xs text-muted"><span>Uploading...</span><span x-text="progress + '%'"></span></div>
                <div class="h-2 overflow-hidden rounded-full bg-slate-100">
                    <div class="h-full rounded-full bg-primary transition-[width]" :style="`width: ${progress}%`"></div>
                </div>
            </div>
            <p wire:loading wire:target="uploads" class="mt-3 text-center text-xs text-muted">Checking files and creating thumbnails...</p>

            @error('uploads')
                <p class="mt-3 text-xs text-danger">{{ $message }}</p>
            @enderror
            @error('uploads.*')
                <p class="mt-3 text-xs text-danger">{{ $message }}</p>
            @enderror

            @if ($results)
                <ul class="mt-4 divide-y divide-line rounded-xl border border-line">
                    @foreach ($results as $result)
                        <li class="flex items-start gap-3 px-3.5 py-2.5 text-[13px]">
                            @if ($result['ok'])
                                <x-lucide-circle-check class="mt-0.5 size-4 shrink-0 text-success" />
                            @else
                                <x-lucide-circle-x class="mt-0.5 size-4 shrink-0 text-danger" />
                            @endif
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-medium">{{ $result['name'] }}</span>
                                <span @class(['block text-xs', 'text-muted' => $result['ok'], 'text-red-700' => ! $result['ok']])>{{ $result['message'] }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Done</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>

{{--
    Global toast stack. Trigger from Livewire with
    $this->dispatch('toast', type: 'success', message: 'Saved');
    or from a redirect with ->with('toast', ['type' => 'success', 'message' => '...']).
--}}
<div x-data="toasts(@js(session('toast')))" x-on:toast.window="push($event.detail)"
    class="pointer-events-none fixed bottom-4 right-4 z-[60] flex w-[calc(100%-2rem)] max-w-sm flex-col gap-2" aria-live="polite">
    <template x-for="toast in items" :key="toast.id">
        <div x-show="toast.visible" x-transition.opacity.duration.200ms
            class="pointer-events-auto flex items-start gap-3 rounded-xl border bg-white px-4 py-3 text-sm shadow-lg"
            :class="{
                'border-emerald-200': toast.type === 'success',
                'border-red-200': toast.type === 'error',
                'border-amber-200': toast.type === 'warning',
                'border-line': toast.type === 'info',
            }">
            <span class="mt-0.5 size-2 shrink-0 rounded-full"
                :class="{
                    'bg-success': toast.type === 'success',
                    'bg-danger': toast.type === 'error',
                    'bg-warning': toast.type === 'warning',
                    'bg-primary': toast.type === 'info',
                }"></span>
            <p class="flex-1 text-ink" x-text="toast.message"></p>
            <button type="button" x-on:click="dismiss(toast.id)" class="text-muted hover:text-ink" aria-label="Dismiss">
                <x-lucide-x class="size-4" />
            </button>
        </div>
    </template>
</div>

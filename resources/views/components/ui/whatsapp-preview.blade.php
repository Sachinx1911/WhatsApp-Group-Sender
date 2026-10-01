{{--
    WhatsApp-style outgoing message bubble.
    Server-rendered:  <x-ui.whatsapp-preview :text="$message" :attachment="$media" />
    Live (Alpine):    <x-ui.whatsapp-preview live="preview" :attachment="$media" />  (inside x-data="messageEditor(...)")
--}}
@props([
    'text' => null,
    'live' => null,         // Alpine expression returning formatted HTML
    'attachment' => null,   // App\Models\Media|null — a single file
    'attachments' => null,  // iterable of App\Models\Media — several files
    'footer' => null,       // optional footer/signature appended below the message
])

@php
    // Accept either prop; "attachments" wins when both are given.
    $files = collect($attachments ?? ($attachment ? [$attachment] : []))->filter()->values();
    $images = $files->filter(fn ($file) => $file->isImage())->values();
    $documents = $files->reject(fn ($file) => $file->isImage())->values();
@endphp

<div {{ $attributes->class('wa-chat rounded-2xl p-4') }}>
    <div class="ml-auto w-fit max-w-[92%] rounded-xl rounded-tr-sm bg-[#d9fdd3] p-1 shadow-[0_1px_0.5px_rgb(0_0_0/0.13)]">
        @if ($images->isNotEmpty())
            {{-- WhatsApp groups several photos into a grid; one photo fills the bubble. --}}
            <div @class([
                'gap-0.5' => $images->count() > 1,
                'grid grid-cols-2' => $images->count() > 1,
            ])>
                @foreach ($images as $image)
                    {{-- Full image (not the square thumbnail) so a single photo keeps its real proportions, as WhatsApp does. --}}
                    <img src="{{ route('media.file', $image) }}" alt="{{ $image->original_name }}"
                        class="h-auto w-full rounded-lg object-cover {{ $images->count() > 1 ? 'aspect-square' : 'max-h-80' }}">
                @endforeach
            </div>
        @endif

        @foreach ($documents as $document)
            <div class="mt-0.5 flex items-center gap-3 rounded-lg bg-[#c9f0c0]/70 px-3 py-2.5">
                <span class="grid h-10 w-8 shrink-0 place-items-center rounded bg-red-500 text-[9px] font-bold text-white">PDF</span>
                <span class="min-w-0">
                    <span class="block truncate text-[13px] font-medium text-[#111b21]">{{ $document->original_name }}</span>
                    <span class="block text-[11px] text-[#667781]">PDF · {{ $document->humanSize() }}</span>
                </span>
            </div>
        @endforeach

        <div class="px-2 pb-1 pt-1.5">
            @if ($live)
                <div class="wa-text" x-html="{{ $live }} || '<span class=&quot;text-[#667781]&quot;>Your message preview will appear here…</span>'"></div>
            @else
                <div class="wa-text">{!! \App\Support\WhatsAppFormatter::toHtml($text) !!}</div>
            @endif

            @if ($footer)
                <div class="wa-text mt-1.5 text-[#667781]">{!! \App\Support\WhatsAppFormatter::toHtml($footer) !!}</div>
            @endif

            <div class="mt-0.5 flex items-center justify-end gap-1 text-[11px] text-[#667781]">
                {{ now()->format('g:i A') }}
                <svg viewBox="0 0 16 11" class="h-2.5 w-4 fill-[#53bdeb]" aria-hidden="true"><path d="M11.07.65 10.2-.23 4.8 5.2 2.4 2.79l-.9.88L4.8 7l6.27-6.35Zm3.5 0-.87-.88L7.3 6.24l-.4-.4-.88.87 1.28 1.3L14.57.65Z" transform="translate(0 2)"/></svg>
            </div>
        </div>
    </div>
</div>

<?php

namespace App\Livewire\Media;

use App\Actions\Media\StoreUploadedMedia;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * "Upload Media" modal: drag & drop or browse, several files at once. Each file is
 * validated and stored on its own, so one bad file does not block the others.
 */
class Uploader extends Component
{
    use WithFileUploads;

    public const MODAL = 'upload-media';

    public array $uploads = [];

    /** @var array<int, array{name: string, ok: bool, message: string}> results of the last upload */
    public array $results = [];

    #[On('upload-media')]
    public function open(): void
    {
        $this->reset();
        $this->resetValidation();
        $this->dispatch('open-modal', self::MODAL);
    }

    public function updatedUploads(): void
    {
        $store = app(StoreUploadedMedia::class);
        $max = (int) config('educationhub.media.max_files_per_upload', 10);
        $files = array_slice($this->uploads, 0, $max);
        $this->results = [];
        $stored = 0;

        foreach ($files as $index => $file) {
            $name = $file->getClientOriginalName();

            try {
                $media = $store->handle($file, "uploads.{$index}");
                $this->results[] = ['name' => $media->original_name, 'ok' => true, 'message' => $media->type->label().' · '.$media->humanSize()];
                $stored++;
            } catch (ValidationException $e) {
                $this->results[] = ['name' => $name, 'ok' => false, 'message' => collect($e->errors())->flatten()->first()];
            }
        }

        if (count($this->uploads) > $max) {
            $this->results[] = ['name' => (count($this->uploads) - $max).' more files', 'ok' => false, 'message' => "Upload at most {$max} files at a time."];
        }

        $this->reset('uploads');

        if ($stored) {
            $this->dispatch('media-uploaded');
            $this->dispatch('toast', type: 'success', message: $stored.' '.str('file')->plural($stored).' uploaded');
        }
    }

    public function render()
    {
        return view('livewire.media.uploader');
    }
}

<?php

namespace App\Actions\Media;

use App\Enums\MediaType;
use App\Models\Media;
use App\Support\ImageProcessor;
use App\Support\StorageUsage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Validates and stores one uploaded image or PDF on the private disk, with a thumbnail
 * for images (docs/MASTER_PROMPT.md §16, §35). Used by the Media Library and Send Message.
 */
class StoreUploadedMedia
{
    /** Detected content type => stored extension. */
    public const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/pdf' => 'pdf',
    ];

    public function __construct(private ImageProcessor $images) {}

    /** @throws ValidationException with the error under $field */
    public function handle(UploadedFile $file, string $field = 'file'): Media
    {
        $mime = $this->validate($file, $field);
        $type = MediaType::fromMime($mime);
        $extension = self::ALLOWED[$mime];

        $directory = 'media/'.now()->format('Y/m');
        $filename = Str::random(40).'.'.$extension;
        $path = $file->storeAs($directory, $filename, 'local');
        $thumbnail = null;

        try {
            if ($type === MediaType::Image) {
                $absolute = Storage::disk('local')->path($path);

                if (config('educationhub.media.compress_images')) {
                    $this->images->compress($absolute, $mime);
                }

                $thumbnail = "{$directory}/thumbnails/{$filename}";
                Storage::disk('local')->put($thumbnail, $this->images->thumbnail($absolute, $mime, (int) config('educationhub.media.thumbnail_size', 320)));
            }
        } catch (Throwable $e) {
            Storage::disk('local')->delete(array_filter([$path, $thumbnail]));
            report($e);

            throw ValidationException::withMessages([$field => 'The image could not be read. It may be damaged — try saving it again as JPG or PNG.']);
        }

        $media = Media::create([
            'filename' => $filename,
            'original_name' => self::displayName($file->getClientOriginalName(), $extension),
            'path' => $path,
            'thumbnail_path' => $thumbnail,
            'mime_type' => $mime,
            'size' => Storage::disk('local')->size($path),
            'type' => $type,
        ]);

        StorageUsage::forget();

        return $media;
    }

    /** @return string the detected MIME type */
    private function validate(UploadedFile $file, string $field): string
    {
        $maxImageKb = (int) config('educationhub.media.max_image_mb', 16) * 1024;
        $maxPdfKb = (int) config('educationhub.media.max_pdf_mb', 100) * 1024;

        // Arr::undot so a field like "uploads.0" is found where the validator looks for it.
        Validator::make(Arr::undot([$field => $file]), [
            $field => ['required', 'file', 'mimes:jpg,jpeg,png,pdf'],
        ], [
            "{$field}.mimes" => 'Only JPG, PNG and PDF files are allowed.',
        ])->validate();

        // The type is taken from the file content; the name must agree (".JPG" from cameras is fine).
        $mime = $file->getMimeType();
        $extension = strtolower($file->getClientOriginalExtension());

        $error = match (true) {
            ! isset(self::ALLOWED[$mime]) || ! in_array($extension, ['jpg', 'jpeg', 'png', 'pdf'], true) => 'Only JPG, PNG and PDF files are allowed.',
            $mime === 'application/pdf' && $file->getSize() > $maxPdfKb * 1024 => 'PDFs can be at most '.($maxPdfKb / 1024).' MB.',
            $mime !== 'application/pdf' && $file->getSize() > $maxImageKb * 1024 => 'Images can be at most '.($maxImageKb / 1024).' MB.',
            $mime === 'application/pdf' && ! str_starts_with((string) file_get_contents($file->getRealPath(), false, null, 0, 5), '%PDF-') => 'This PDF file appears to be damaged.',
            $mime !== 'application/pdf' && ! @getimagesize($file->getRealPath()) => 'The image could not be read. It may be damaged.',
            default => null,
        };

        if ($error) {
            throw ValidationException::withMessages([$field => $error]);
        }

        return $mime;
    }

    /** "../Current Affairs\u0000 30-09.PDF" → "Current Affairs 30-09.pdf" (safe to show and to download as). */
    public static function displayName(string $original, string $extension): string
    {
        $base = pathinfo(str_replace('\\', '/', $original), PATHINFO_FILENAME);
        $base = trim(preg_replace('/[\x00-\x1F\x7F\/:*?"<>|]+/u', ' ', $base));
        $base = Str::limit(preg_replace('/\s+/u', ' ', $base), 150, '');

        return ($base !== '' ? $base : 'file').'.'.$extension;
    }
}

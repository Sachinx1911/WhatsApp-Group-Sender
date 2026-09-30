<?php

namespace App\Http\Controllers;

use App\Models\Media;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves media from the private disk to the signed-in admin only
 * (files are never publicly reachable, docs/MASTER_PROMPT.md §36).
 */
class MediaFileController extends Controller
{
    public function show(Media $media): StreamedResponse
    {
        return $this->serve($media->path, $media);
    }

    public function thumbnail(Media $media): StreamedResponse
    {
        abort_unless($media->isImage(), 404);

        return $this->serve($media->thumbnail_path ?? $media->path, $media);
    }

    public function download(Media $media): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($media->path), 404);

        return Storage::disk('local')->download($media->path, $media->original_name);
    }

    private function serve(string $path, Media $media): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, $media->original_name, [
            'Content-Type' => $media->mime_type,
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

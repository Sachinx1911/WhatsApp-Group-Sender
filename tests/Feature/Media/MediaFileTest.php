<?php

namespace Tests\Feature\Media;

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaFileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_files_are_only_served_to_the_signed_in_admin(): void
    {
        $media = Media::factory()->create();
        Storage::disk('local')->put($media->path, 'png-bytes');

        $this->get(route('media.file', $media))->assertRedirect('/login');
        $this->get(route('media.thumbnail', $media))->assertRedirect('/login');
        $this->get(route('media.download', $media))->assertRedirect('/login');
    }

    public function test_file_thumbnail_and_download(): void
    {
        $this->actingAs(User::factory()->create());
        $media = Media::factory()->create(['original_name' => 'poster.png', 'thumbnail_path' => 'media/thumbnails/x.png']);
        Storage::disk('local')->put($media->path, 'full-image');
        Storage::disk('local')->put($media->thumbnail_path, 'thumb-image');

        $file = $this->get(route('media.file', $media))->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame('full-image', $file->streamedContent());

        $this->assertSame('thumb-image', $this->get(route('media.thumbnail', $media))->assertOk()->streamedContent());

        $this->get(route('media.download', $media))
            ->assertOk()
            ->assertDownload('poster.png');
    }

    public function test_pdfs_have_no_thumbnail_and_missing_files_return_404(): void
    {
        $this->actingAs(User::factory()->create());
        $pdf = Media::factory()->pdf()->create();
        Storage::disk('local')->put($pdf->path, '%PDF-1.4');

        $this->get(route('media.thumbnail', $pdf))->assertNotFound();
        $this->get(route('media.file', $pdf))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $missing = Media::factory()->create();
        $this->get(route('media.file', $missing))->assertNotFound();
    }
}

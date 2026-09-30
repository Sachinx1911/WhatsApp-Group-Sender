<?php

namespace Tests\Feature\Media;

use App\Actions\Media\StoreUploadedMedia;
use App\Enums\MediaType;
use App\Livewire\Media\Uploader;
use App\Models\Media;
use App\Models\User;
use App\Support\StorageUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class StoreUploadedMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function store(UploadedFile $file)
    {
        return app(StoreUploadedMedia::class)->handle($file);
    }

    /**
     * A real temporary upload. Unlike UploadedFile::fake(), its MIME type is detected
     * from the content, as it is for files uploaded in the browser.
     */
    private function realUpload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function pdf(string $name = 'notes.pdf', int $padding = 0): UploadedFile
    {
        return $this->realUpload($name, "%PDF-1.4\n".str_repeat('0', $padding)."\n%%EOF\n");
    }

    private function errorFor(UploadedFile $file): string
    {
        try {
            $this->store($file);
        } catch (ValidationException $e) {
            return collect($e->errors())->flatten()->first();
        }

        $this->fail('Expected the upload to be rejected.');
    }

    public function test_image_is_stored_privately_with_a_random_name_and_thumbnail(): void
    {
        $media = $this->store(UploadedFile::fake()->image('चाचणी निकाल.jpg', 1200, 900));

        $this->assertSame(MediaType::Image, $media->type);
        $this->assertSame('image/jpeg', $media->mime_type);
        $this->assertSame('चाचणी निकाल.jpg', $media->original_name);
        $this->assertMatchesRegularExpression('#^media/\d{4}/\d{2}/[A-Za-z0-9]{40}\.jpg$#', $media->path);
        Storage::disk('local')->assertExists([$media->path, $media->thumbnail_path]);

        [$width, $height] = getimagesizefromstring(Storage::disk('local')->get($media->thumbnail_path));
        $this->assertSame([320, 320], [$width, $height]);
        $this->assertSame(Storage::disk('local')->size($media->path), $media->size);
    }

    public function test_png_keeps_png_format(): void
    {
        // A real PNG with a camera-style upper-case extension.
        $image = imagecreatetruecolor(400, 600);
        ob_start();
        imagepng($image);
        $media = $this->store($this->realUpload('poster.PNG', ob_get_clean()));

        $this->assertSame('image/png', $media->mime_type);
        $this->assertStringEndsWith('.png', $media->path);
        $this->assertSame('poster.png', $media->original_name);
    }

    public function test_pdf_is_stored_without_a_thumbnail(): void
    {
        $media = $this->store($this->pdf('Current Affairs 30-09.pdf'));

        $this->assertSame(MediaType::Pdf, $media->type);
        $this->assertNull($media->thumbnail_path);
        $this->assertStringEndsWith('.pdf', $media->path);
    }

    public function test_type_is_checked_from_the_file_content(): void
    {
        $this->assertSame('Only JPG, PNG and PDF files are allowed.', $this->errorFor($this->realUpload('photo.png', 'just text')));
        $this->assertSame('Only JPG, PNG and PDF files are allowed.', $this->errorFor(UploadedFile::fake()->image('anim.gif')));
        $this->assertSame('Only JPG, PNG and PDF files are allowed.', $this->errorFor($this->realUpload('run.exe.pdf', "MZ\x90\x00\x03\x00binary")));
        $this->assertSame('Only JPG, PNG and PDF files are allowed.', $this->errorFor($this->realUpload('page.pdf', '<html><script>alert(1)</script></html>')));

        $this->assertSame(0, Media::count());
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_size_limits_depend_on_the_type(): void
    {
        config(['educationhub.media.max_pdf_mb' => 1, 'educationhub.media.max_image_mb' => 1]);

        $this->assertSame('PDFs can be at most 1 MB.', $this->errorFor($this->pdf('big.pdf', 1024 * 1024 + 10)));
    }

    public function test_display_name_is_sanitized(): void
    {
        $this->assertSame('Current Affairs 30-09.pdf', StoreUploadedMedia::displayName("..\\..\\Current Affairs\x00 30-09.PDF", 'pdf'));
        $this->assertSame('file.png', StoreUploadedMedia::displayName('???.png', 'png'));
        $this->assertSame(154, mb_strlen(StoreUploadedMedia::displayName(str_repeat('अ', 300).'.jpg', 'jpg')));
    }

    public function test_large_photos_are_compressed_when_enabled(): void
    {
        config(['educationhub.media.compress_images' => true]);

        $media = $this->store(UploadedFile::fake()->image('big.jpg', 4000, 3000));

        [$width, $height] = getimagesizefromstring(Storage::disk('local')->get($media->path));
        $this->assertSame([2560, 1920], [$width, $height]);
    }

    public function test_upload_refreshes_the_storage_usage_card(): void
    {
        Cache::put(StorageUsage::CACHE_KEY, 0);

        $this->store($this->pdf());

        $this->assertFalse(Cache::has(StorageUsage::CACHE_KEY));
    }

    public function test_uploader_stores_each_file_and_reports_failures_separately(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(Uploader::class)
            ->call('open')
            ->set('uploads', [
                UploadedFile::fake()->image('mcq.png', 600, 600),
                UploadedFile::fake()->createWithContent('notes.pdf', "%PDF-1.4\n%%EOF\n"),
                UploadedFile::fake()->createWithContent('fake.png', 'not an image'),
            ])
            ->assertSet('uploads', [])
            ->assertSet('results', fn ($results) => array_column($results, 'ok') === [true, true, false])
            ->assertSee('could not be read')
            ->assertDispatched('media-uploaded')
            ->assertDispatched('toast');

        $this->assertSame(2, Media::count());
    }

    public function test_uploader_limits_files_per_upload(): void
    {
        $this->actingAs(User::factory()->create());
        config(['educationhub.media.max_files_per_upload' => 2]);

        Livewire::test(Uploader::class)
            ->set('uploads', array_map(fn ($n) => UploadedFile::fake()->createWithContent("{$n}.pdf", "%PDF-1.4\n%%EOF\n"), ['a', 'b', 'c']))
            ->assertSee('Upload at most 2 files at a time.');

        $this->assertSame(2, Media::count());
    }
}

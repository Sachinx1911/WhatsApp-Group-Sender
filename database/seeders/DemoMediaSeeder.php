<?php

namespace Database\Seeders;

use App\Enums\MediaType;
use App\Models\Media;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Writes real sample files to the private disk (storage/app/private/media/demo)
 * so previews and sending work with seeded media. Only the demo folder is ever
 * cleared; uploaded media is never touched.
 */
class DemoMediaSeeder extends Seeder
{
    private const DIR = 'media/demo';

    /** original name => [title, subtitle, background color] */
    private const IMAGES = [
        'daily_mcq_30_09_2026.png' => ['Daily MCQ Practice', '20 Questions - 15 Minutes', [37, 99, 235]],
        'mpsc_timetable_october.png' => ['MPSC Timetable', 'October 2026', [15, 23, 42]],
        'police_bharti_poster.png' => ['Police Bharti 2026', 'Practice Series', [124, 58, 237]],
        'result_announcement.png' => ['Test Results', 'Weekly Test 12', [16, 185, 129]],
    ];

    /** original name => [title, lines] */
    private const PDFS = [
        'Current_Affairs_29_09_2026.pdf' => ['Current Affairs - 29 September 2026', [
            '1. National: New education policy review committee formed.',
            '2. Maharashtra: State announces recruitment calendar for 2026-27.',
            '3. Economy: RBI keeps repo rate unchanged.',
            '4. Sports: India wins the Asian hockey championship.',
        ]],
        'Current_Affairs_30_09_2026.pdf' => ['Current Affairs - 30 September 2026', [
            '1. National: Digital library scheme extended to all districts.',
            '2. Maharashtra: New skill centres opened in 12 districts.',
            '3. Science: ISRO schedules next PSLV launch.',
            '4. Awards: National teachers awards announced.',
        ]],
        'Police_Bharti_Practice_Paper_05.pdf' => ['Police Bharti - Practice Paper 05', [
            'Total questions: 100    Time: 90 minutes',
            'Section A: General Knowledge (25)',
            'Section B: Marathi Grammar (25)',
            'Section C: Mathematics (25)',
            'Section D: Reasoning (25)',
        ]],
        'Free_Batch_Weekly_Notes.pdf' => ['Free Batch - Weekly Notes', [
            'Topic 1: Indian Constitution - Fundamental Rights',
            'Topic 2: Maharashtra Geography - Rivers',
            'Topic 3: Modern History - Social reformers',
        ]],
    ];

    public function run(): void
    {
        $disk = Storage::disk('local');

        Media::where('path', 'like', self::DIR.'/%')->delete();
        $disk->deleteDirectory(self::DIR);

        foreach (self::IMAGES as $name => [$title, $subtitle, $color]) {
            $filename = Str::random(40).'.png';
            $path = self::DIR.'/'.$filename;
            $thumbnail = self::DIR.'/thumbnails/'.$filename;

            [$full, $thumb] = $this->renderImage($title, $subtitle, $color);
            $disk->put($path, $full);
            $disk->put($thumbnail, $thumb);

            $this->record($name, $filename, $path, $thumbnail, 'image/png', strlen($full), MediaType::Image);
        }

        foreach (self::PDFS as $name => [$title, $lines]) {
            $filename = Str::random(40).'.pdf';
            $path = self::DIR.'/'.$filename;
            $pdf = $this->renderPdf($title, $lines);
            $disk->put($path, $pdf);

            $this->record($name, $filename, $path, null, 'application/pdf', strlen($pdf), MediaType::Pdf);
        }
    }

    private function record(string $name, string $filename, string $path, ?string $thumbnail, string $mime, int $size, MediaType $type): void
    {
        $createdAt = now()->subDays(mt_rand(1, 12))->setTime(mt_rand(6, 21), mt_rand(0, 59));

        $media = Media::create([
            'filename' => $filename,
            'original_name' => $name,
            'path' => $path,
            'thumbnail_path' => $thumbnail,
            'mime_type' => $mime,
            'size' => $size,
            'type' => $type,
        ]);

        $media->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();
    }

    /** @return array{0: string, 1: string} PNG bytes for the full image and a 320px thumbnail. */
    private function renderImage(string $title, string $subtitle, array $rgb): array
    {
        $size = 1080;
        $image = imagecreatetruecolor($size, $size);
        $background = imagecolorallocate($image, ...$rgb);
        $white = imagecolorallocate($image, 255, 255, 255);
        $soft = imagecolorallocatealpha($image, 255, 255, 255, 100);

        imagefilledrectangle($image, 0, 0, $size, $size, $background);
        imagefilledellipse($image, 930, 150, 420, 420, $soft);
        imagefilledellipse($image, 120, 980, 360, 360, $soft);

        $font = collect(['C:\\Windows\\Fonts\\segoeuib.ttf', 'C:\\Windows\\Fonts\\arialbd.ttf'])->first(fn ($f) => is_file($f));

        if ($font) {
            imagettftext($image, 30, 0, 90, 200, $white, $font, 'EDUCATION HUB');
            imagettftext($image, 72, 0, 90, 520, $white, $font, $title);
            imagettftext($image, 40, 0, 90, 610, $white, $font, $subtitle);
        } else {
            imagestring($image, 5, 90, 200, 'EDUCATION HUB', $white);
            imagestring($image, 5, 90, 520, $title, $white);
            imagestring($image, 5, 90, 610, $subtitle, $white);
        }

        $thumb = imagecreatetruecolor(320, 320);
        imagecopyresampled($thumb, $image, 0, 0, 0, 0, 320, 320, $size, $size);

        return [$this->toPng($image), $this->toPng($thumb)];
    }

    private function toPng(\GdImage $image): string
    {
        ob_start();
        imagepng($image, null, 9);

        return ob_get_clean();
    }

    /** Minimal valid single-page PDF (Helvetica, ASCII text). */
    private function renderPdf(string $title, array $lines): string
    {
        $escape = fn (string $text) => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);

        $stream = "BT /F2 20 Tf 60 770 Td ({$escape('Education Hub')}) Tj ET\n";
        $stream .= "BT /F2 16 Tf 60 730 Td ({$escape($title)}) Tj ET\n";
        $y = 690;
        foreach ($lines as $line) {
            $stream .= "BT /F1 12 Tf 60 {$y} Td ({$escape($line)}) Tj ET\n";
            $y -= 24;
        }

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> /Contents 4 0 R >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}endstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}

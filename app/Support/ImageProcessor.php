<?php

namespace App\Support;

use GdImage;
use RuntimeException;

/**
 * Thumbnails and optional compression for uploaded JPG/PNG images (GD).
 * Phone photos are turned upright using their EXIF orientation.
 */
class ImageProcessor
{
    public const COMPRESS_MAX_SIDE = 2560;

    public const JPEG_QUALITY = 85;

    /** Square, centre-cropped PNG/JPEG thumbnail bytes. */
    public function thumbnail(string $path, string $mime, int $size): string
    {
        $image = $this->load($path, $mime);
        [$width, $height] = [imagesx($image), imagesy($image)];
        $side = min($width, $height);

        $thumb = imagecreatetruecolor($size, $size);
        $this->prepareCanvas($thumb, $mime);
        imagecopyresampled($thumb, $image, 0, 0, (int) (($width - $side) / 2), (int) (($height - $side) / 2), $size, $size, $side, $side);

        return $this->encode($thumb, $mime);
    }

    /**
     * Shrinks a photo whose longest side exceeds COMPRESS_MAX_SIDE and rewrites it in place.
     * Returns true when the file was changed.
     */
    public function compress(string $path, string $mime): bool
    {
        [$width, $height] = getimagesize($path) ?: [0, 0];

        if (max($width, $height) <= self::COMPRESS_MAX_SIDE) {
            return false;
        }

        $image = $this->load($path, $mime);
        $scale = self::COMPRESS_MAX_SIDE / max(imagesx($image), imagesy($image));
        $resized = imagecreatetruecolor((int) round(imagesx($image) * $scale), (int) round(imagesy($image) * $scale));
        $this->prepareCanvas($resized, $mime);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, imagesx($resized), imagesy($resized), imagesx($image), imagesy($image));

        file_put_contents($path, $this->encode($resized, $mime));

        return true;
    }

    private function load(string $path, string $mime): GdImage
    {
        $this->ensureMemoryFor($path);

        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            default => false,
        };

        if (! $image) {
            throw new RuntimeException('The image could not be read. It may be damaged.');
        }

        return $mime === 'image/jpeg' ? $this->orient($image, $path) : $image;
    }

    /**
     * GD holds a decoded image at ~5 bytes per pixel, and rotating keeps two copies. A 24 MP
     * phone photo therefore needs ~250 MB, more than the default memory_limit. Running out
     * is a fatal error no catch block sees, which leaves the uploaded file on disk with no
     * database row. Raise the limit for this request when possible; refuse otherwise.
     */
    private function ensureMemoryFor(string $path): void
    {
        [$width, $height] = @getimagesize($path) ?: [0, 0];
        $needed = (int) ($width * $height * 5 * 2) + 32 * 1024 * 1024 + memory_get_usage(true);
        $limit = $this->bytes((string) ini_get('memory_limit'));

        if ($limit < 0 || $needed <= $limit) {
            return;
        }

        if (@ini_set('memory_limit', (string) $needed) === false || $this->bytes((string) ini_get('memory_limit')) < $needed) {
            throw new RuntimeException('This photo is too large to process (about '.round($width * $height / 1e6).' megapixels). Resize it below 4000×4000 and upload again.');
        }
    }

    private function bytes(string $ini): int
    {
        $ini = trim($ini);
        if ($ini === '' || $ini === '-1') {
            return -1;
        }

        $value = (int) $ini;

        return match (strtolower(substr($ini, -1))) {
            'g' => $value * 1024 ** 3,
            'm' => $value * 1024 ** 2,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /** Rotate according to EXIF so portrait phone photos are not shown sideways. */
    private function orient(GdImage $image, string $path): GdImage
    {
        $orientation = function_exists('exif_read_data') ? (@exif_read_data($path)['Orientation'] ?? 1) : 1;

        return match ((int) $orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };
    }

    private function prepareCanvas(GdImage $canvas, string $mime): void
    {
        if ($mime === 'image/png') {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        }
    }

    private function encode(GdImage $image, string $mime): string
    {
        ob_start();
        $mime === 'image/png' ? imagepng($image, null, 8) : imagejpeg($image, null, self::JPEG_QUALITY);

        return (string) ob_get_clean();
    }
}

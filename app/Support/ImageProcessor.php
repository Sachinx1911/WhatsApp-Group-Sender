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

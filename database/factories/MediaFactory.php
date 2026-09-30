<?php

namespace Database\Factories;

use App\Enums\MediaType;
use App\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Creates database records only; no file is written to disk.
 *
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    public function definition(): array
    {
        $filename = Str::random(40).'.png';

        return [
            'filename' => $filename,
            'original_name' => fake()->slug(3).'.png',
            'path' => 'media/'.$filename,
            'thumbnail_path' => null,
            'mime_type' => 'image/png',
            'size' => fake()->numberBetween(50_000, 2_000_000),
            'type' => MediaType::Image,
            'usage_count' => 0,
        ];
    }

    public function pdf(): static
    {
        return $this->state(function () {
            $filename = Str::random(40).'.pdf';

            return [
                'filename' => $filename,
                'original_name' => fake()->slug(3).'.pdf',
                'path' => 'media/'.$filename,
                'mime_type' => 'application/pdf',
                'type' => MediaType::Pdf,
            ];
        });
    }
}

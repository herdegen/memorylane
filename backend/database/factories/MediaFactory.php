<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Media>
 */
class MediaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $types = ['photo', 'video', 'document'];
        $type = fake()->randomElement($types);

        $mimeTypes = [
            'photo' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            'video' => ['video/mp4', 'video/quicktime', 'video/x-msvideo'],
            'document' => ['application/pdf', 'application/msword', 'text/plain'],
        ];

        $extensions = [
            'photo' => ['jpg', 'png', 'gif', 'webp'],
            'video' => ['mp4', 'mov', 'avi'],
            'document' => ['pdf', 'doc', 'txt'],
        ];

        $mimeType = fake()->randomElement($mimeTypes[$type]);
        $extension = fake()->randomElement($extensions[$type]);
        $filename = fake()->uuid() . '.' . $extension;

        return [
            'user_id' => User::factory(),
            'type' => $type,
            'original_name' => fake()->words(3, true) . '.' . $extension,
            'file_path' => 'media/' . $filename,
            'mime_type' => $mimeType,
            'size' => fake()->numberBetween(100000, 10000000), // 100KB to 10MB
            'width' => $type === 'photo' ? fake()->numberBetween(800, 4000) : null,
            'height' => $type === 'photo' ? fake()->numberBetween(600, 3000) : null,
            'duration' => $type === 'video' ? fake()->numberBetween(10, 600) : null,
            'uploaded_at' => now(),
            'taken_at' => fake()->optional(0.8)->dateTimeBetween('-2 years', 'now'),
        ];
    }

    /**
     * Indicate that the media is a photo.
     */
    public function photo(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'photo',
            'mime_type' => 'image/jpeg',
            'original_name' => fake()->words(2, true) . '.jpg',
            'file_path' => 'media/' . fake()->uuid() . '.jpg',
            'width' => fake()->numberBetween(800, 4000),
            'height' => fake()->numberBetween(600, 3000),
            'duration' => null,
        ]);
    }

    /**
     * Indicate that the media is a video.
     */
    public function video(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'video',
            'mime_type' => 'video/mp4',
            'original_name' => fake()->words(2, true) . '.mp4',
            'file_path' => 'media/' . fake()->uuid() . '.mp4',
            'width' => null,
            'height' => null,
            'duration' => fake()->numberBetween(10, 600),
        ]);
    }

    /**
     * Indicate that the media is a document.
     */
    public function document(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'document',
            'mime_type' => 'application/pdf',
            'original_name' => fake()->words(2, true) . '.pdf',
            'file_path' => 'media/' . fake()->uuid() . '.pdf',
            'width' => null,
            'height' => null,
            'duration' => null,
        ]);
    }
}

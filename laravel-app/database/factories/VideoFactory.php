<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Video>
 */
class VideoFactory extends Factory
{
    public function definition(): array
    {
        $formats = ['mp4', 'mov', 'webm', 'avi'];
        $format = fake()->randomElement($formats);

        return [
            'user_id' => User::factory(),
            'uuid' => Str::uuid(),
            'original_filename' => fake()->word().'.'.$format,
            'storage_path' => 'uploads/users/1/'.Str::uuid().'.'.$format,
            'file_size_bytes' => fake()->numberBetween(1024 * 1024, 500 * 1024 * 1024),
            'content_hash' => fake()->sha256(),
            'mime_type' => 'video/'.$format,
            'duration_seconds' => fake()->randomFloat(1, 5, 600),
            'width' => fake()->randomElement([1280, 1920, 3840]),
            'height' => fake()->randomElement([720, 1080, 2160]),
            'status' => 'pending',
            'error_message' => null,
        ];
    }

    public function queued(): static
    {
        return $this->state(fn () => ['status' => 'queued']);
    }

    public function processing(): static
    {
        return $this->state(fn () => ['status' => 'processing']);
    }

    public function completed(): static
    {
        return $this->state(fn () => ['status' => 'completed']);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => 'failed',
            'error_message' => fake()->sentence(),
        ]);
    }
}

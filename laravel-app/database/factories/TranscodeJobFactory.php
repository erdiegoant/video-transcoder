<?php

namespace Database\Factories;

use App\Models\TranscodeJob;
use App\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TranscodeJob>
 */
class TranscodeJobFactory extends Factory
{
    public function definition(): array
    {
        return [
            'video_id' => Video::factory(),
            'job_uuid' => Str::uuid(),
            'operation_type' => 'transcode',
            'target_format' => 'mp4',
            'target_resolution' => '1280x720',
            'trim_start_sec' => null,
            'trim_end_sec' => null,
            'thumbnail_at_sec' => null,
            'output_path' => null,
            'status' => 'pending',
            'attempts' => 0,
            'max_attempts' => 3,
            'worker_id' => null,
            'error_message' => null,
            'started_at' => null,
            'completed_at' => null,
        ];
    }

    public function transcode(): static
    {
        return $this->state(fn () => [
            'operation_type' => 'transcode',
            'target_format' => fake()->randomElement(['mp4', 'webm']),
            'target_resolution' => fake()->randomElement(['1280x720', '1920x1080']),
        ]);
    }

    public function thumbnail(): static
    {
        return $this->state(fn () => [
            'operation_type' => 'thumbnail',
            'target_format' => 'jpg',
            'thumbnail_at_sec' => fake()->randomFloat(1, 0, 30),
        ]);
    }

    public function trim(): static
    {
        return $this->state(fn () => [
            'operation_type' => 'trim',
            'trim_start_sec' => fake()->randomFloat(1, 0, 10),
            'trim_end_sec' => fake()->randomFloat(1, 30, 120),
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn () => [
            'status' => 'processing',
            'attempts' => 1,
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
            'attempts' => 1,
            'started_at' => now()->subMinutes(2),
            'completed_at' => now(),
            'output_path' => 'outputs/users/1/'.Str::uuid().'/video.mp4',
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => 'failed',
            'attempts' => 3,
            'error_message' => fake()->sentence(),
        ]);
    }
}

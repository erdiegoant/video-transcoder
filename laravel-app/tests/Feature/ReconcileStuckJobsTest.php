<?php

use App\Enums\TranscodeStatus;
use App\Models\TranscodeJob;
use App\Models\Video;
use Illuminate\Support\Facades\Redis;

test('requeues stuck processing job within attempt limit', function () {
    Redis::shouldReceive('rpush')->once()->andReturn(1);

    $video = Video::factory()->processing()->create();
    $job = TranscodeJob::factory()->processing()->create([
        'video_id' => $video->id,
        'attempts' => 1,
        'max_attempts' => 3,
        'updated_at' => now()->subMinutes(31),
    ]);

    $this->artisan('transcode:reconcile')->assertSuccessful();

    expect($job->fresh()->status)->toBe(TranscodeStatus::Queued);
    expect($job->fresh()->attempts)->toBe(2);
});

test('does not requeue job updated recently', function () {
    $video = Video::factory()->processing()->create();
    $job = TranscodeJob::factory()->processing()->create([
        'video_id' => $video->id,
        'updated_at' => now()->subMinutes(10),
    ]);

    $this->artisan('transcode:reconcile')->assertSuccessful();

    expect($job->fresh()->status)->toBe(TranscodeStatus::Processing);
});

test('marks job failed when max attempts exhausted', function () {
    $video = Video::factory()->processing()->create();
    $job = TranscodeJob::factory()->processing()->create([
        'video_id' => $video->id,
        'attempts' => 3,
        'max_attempts' => 3,
        'updated_at' => now()->subMinutes(31),
    ]);

    $this->artisan('transcode:reconcile')->assertSuccessful();

    expect($job->fresh()->status)->toBe(TranscodeStatus::Failed);
    expect($job->fresh()->error_message)->not->toBeNull();
});

test('marks parent video failed when all jobs exhaust max attempts', function () {
    $video = Video::factory()->processing()->create();
    TranscodeJob::factory()->processing()->create([
        'video_id' => $video->id,
        'attempts' => 3,
        'max_attempts' => 3,
        'updated_at' => now()->subMinutes(31),
    ]);

    $this->artisan('transcode:reconcile')->assertSuccessful();

    expect($video->fresh()->status)->toBe(TranscodeStatus::Failed);
});

test('does not fail video when other jobs are still pending', function () {
    $video = Video::factory()->processing()->create();

    TranscodeJob::factory()->processing()->create([
        'video_id' => $video->id,
        'attempts' => 3,
        'max_attempts' => 3,
        'updated_at' => now()->subMinutes(31),
    ]);

    TranscodeJob::factory()->create(['video_id' => $video->id, 'status' => 'queued']);

    $this->artisan('transcode:reconcile')->assertSuccessful();

    expect($video->fresh()->status)->toBe(TranscodeStatus::Processing);
});

test('outputs a message when no stuck jobs exist', function () {
    $this->artisan('transcode:reconcile')
        ->expectsOutput('No stuck jobs found.')
        ->assertSuccessful();
});

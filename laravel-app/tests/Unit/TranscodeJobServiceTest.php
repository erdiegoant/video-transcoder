<?php

use App\Jobs\DispatchTranscodeJob;
use App\Models\TranscodeJob;
use App\Models\Video;
use App\Services\TranscodeJobService;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    $this->service = new TranscodeJobService;
});

it('builds a valid redis payload matching the go worker contract', function () {
    $video = Video::factory()->create();
    $job = TranscodeJob::factory()->transcode()->create(['video_id' => $video->id]);

    $payload = $this->service->buildSingleJobPayload($job);

    expect($payload)->toHaveKeys([
        'job_uuid', 'video_id', 'user_id',
        'source_bucket', 'source_key',
        'output_bucket', 'output_key_prefix',
        'operations', 'callback_url',
        'max_attempts', 'enqueued_at',
    ]);
    expect($payload)->not->toHaveKey('callback_secret');
    expect($payload['job_uuid'])->toBe($job->job_uuid);
    expect($payload['video_id'])->toBe($video->id);
    expect($payload['user_id'])->toBe($video->user_id);
});

it('payload contains exactly one operation', function () {
    $video = Video::factory()->create();
    $job = TranscodeJob::factory()->transcode()->create(['video_id' => $video->id]);

    $payload = $this->service->buildSingleJobPayload($job);

    expect($payload['operations'])->toHaveCount(1);
});

it('sets correct output key prefix based on user and video uuid', function () {
    $video = Video::factory()->create();
    $job = TranscodeJob::factory()->transcode()->create(['video_id' => $video->id]);

    $payload = $this->service->buildSingleJobPayload($job);

    expect($payload['output_key_prefix'])->toBe("outputs/users/{$video->user_id}/{$video->uuid}/");
});

it('builds correct transcode operation flags', function () {
    $video = Video::factory()->create();
    $job = TranscodeJob::factory()->create([
        'video_id' => $video->id,
        'operation_type' => 'transcode',
        'target_format' => 'webm',
        'target_resolution' => '1920x1080',
    ]);

    $operation = $this->service->buildSingleJobPayload($job)['operations'][0];

    expect($operation['type'])->toBe('transcode');
    expect($operation['format'])->toBe('webm');
    expect($operation['resolution'])->toBe('1920x1080');
    expect($operation)->toHaveKey('crf');
});

it('builds correct thumbnail operation flags', function () {
    $video = Video::factory()->create();
    $job = TranscodeJob::factory()->create([
        'video_id' => $video->id,
        'operation_type' => 'thumbnail',
        'thumbnail_at_sec' => 5.0,
        'target_format' => 'jpg',
    ]);

    $operation = $this->service->buildSingleJobPayload($job)['operations'][0];

    expect($operation['type'])->toBe('thumbnail');
    expect($operation['at_second'])->toBe(5.0);
    expect($operation['format'])->toBe('jpg');
});

it('builds correct trim operation flags', function () {
    $video = Video::factory()->create();
    $job = TranscodeJob::factory()->create([
        'video_id' => $video->id,
        'operation_type' => 'trim',
        'trim_start_sec' => 10.0,
        'trim_end_sec' => 60.0,
    ]);

    $operation = $this->service->buildSingleJobPayload($job)['operations'][0];

    expect($operation['type'])->toBe('trim');
    expect($operation['trim_start'])->toBe(10.0);
    expect($operation['trim_end'])->toBe(60.0);
});

it('includes trim params in transcode operation when set on job', function () {
    $video = Video::factory()->create();
    $job = TranscodeJob::factory()->create([
        'video_id' => $video->id,
        'operation_type' => 'transcode',
        'target_format' => 'mp4',
        'target_resolution' => '1280x720',
        'trim_start_sec' => 5.0,
        'trim_end_sec' => 30.0,
    ]);

    $operation = $this->service->buildSingleJobPayload($job)['operations'][0];

    expect($operation['type'])->toBe('transcode')
        ->and($operation['format'])->toBe('mp4')
        ->and($operation['trim_start'])->toBe(5.0)
        ->and($operation['trim_end'])->toBe(30.0);
});

it('does not include trim keys in transcode operation when not set', function () {
    $video = Video::factory()->create();
    $job = TranscodeJob::factory()->transcode()->create(['video_id' => $video->id]);

    $operation = $this->service->buildSingleJobPayload($job)['operations'][0];

    expect($operation)->not->toHaveKey('trim_start')
        ->and($operation)->not->toHaveKey('trim_end');
});

it('dispatch job pushes one redis message per transcode job', function () {
    Redis::shouldReceive('rpush')->twice()->andReturn(1);

    $video = Video::factory()->create();
    TranscodeJob::factory()->transcode()->create(['video_id' => $video->id]);
    TranscodeJob::factory()->thumbnail()->create(['video_id' => $video->id]);

    $video->load('transcodeJobs');

    dispatch(new DispatchTranscodeJob($video));
});

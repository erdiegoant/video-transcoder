<?php

use App\Models\TranscodeJob;
use App\Models\Video;
use App\Services\TranscodeJobService;

beforeEach(function () {
    $this->service = new TranscodeJobService;
});

it('builds a valid redis payload matching the go worker contract', function () {
    $video = Video::factory()->create();
    $job = TranscodeJob::factory()->transcode()->create(['video_id' => $video->id]);
    $video->setRelation('transcodeJobs', collect([$job]));

    $payload = $this->service->buildPayload($video);

    expect($payload)->toHaveKeys([
        'job_uuid', 'video_id', 'user_id',
        'source_bucket', 'source_key',
        'output_bucket', 'output_key_prefix',
        'operations', 'callback_url', 'callback_secret',
        'max_attempts', 'enqueued_at',
    ]);
    expect($payload['video_id'])->toBe($video->id);
    expect($payload['user_id'])->toBe($video->user_id);
});

it('includes all operations in the payload', function () {
    $video = Video::factory()->create();
    $transcodeJob = TranscodeJob::factory()->transcode()->create(['video_id' => $video->id]);
    $thumbnailJob = TranscodeJob::factory()->thumbnail()->create(['video_id' => $video->id]);
    $video->setRelation('transcodeJobs', collect([$transcodeJob, $thumbnailJob]));

    $payload = $this->service->buildPayload($video);

    expect($payload['operations'])->toHaveCount(2);
    expect(collect($payload['operations'])->pluck('type')->all())->toContain('transcode', 'thumbnail');
});

it('sets correct output key prefix based on user and video uuid', function () {
    $video = Video::factory()->create();
    $job = TranscodeJob::factory()->transcode()->create(['video_id' => $video->id]);
    $video->setRelation('transcodeJobs', collect([$job]));

    $payload = $this->service->buildPayload($video);

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
    $video->setRelation('transcodeJobs', collect([$job]));

    $payload = $this->service->buildPayload($video);
    $operation = $payload['operations'][0];

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
    $video->setRelation('transcodeJobs', collect([$job]));

    $payload = $this->service->buildPayload($video);
    $operation = $payload['operations'][0];

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
    $video->setRelation('transcodeJobs', collect([$job]));

    $payload = $this->service->buildPayload($video);
    $operation = $payload['operations'][0];

    expect($operation['type'])->toBe('trim');
    expect($operation['start_sec'])->toBe(10.0);
    expect($operation['end_sec'])->toBe(60.0);
});

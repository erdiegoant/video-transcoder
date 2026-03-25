<?php

use App\Enums\TranscodeStatus;
use App\Events\TranscodeCompleted;
use App\Models\TranscodeJob;
use App\Models\Video;
use Illuminate\Support\Facades\Event;

function webhookPayload(TranscodeJob $job, string $status = 'completed'): array
{
    return [
        'job_uuid' => $job->job_uuid,
        'video_id' => $job->video_id,
        'status' => $status,
        'worker_id' => 'go-worker-pod-abc123',
        'outputs' => $status === 'completed' ? [
            ['operation' => 'transcode', 'output_key' => 'outputs/users/1/abc/video.mp4', 'file_size_bytes' => 2048576],
        ] : [],
        'error_message' => $status === 'failed' ? 'FFmpeg crashed' : '',
        'started_at' => now()->subMinute()->toIso8601String(),
        'completed_at' => now()->toIso8601String(),
    ];
}

function signedRequest(array $payload, string $secret = 'test-secret'): string
{
    return 'sha256='.hash_hmac('sha256', json_encode($payload), $secret);
}

beforeEach(function () {
    config(['services.transcoder.webhook_secret' => 'test-secret']);
    Event::fake();
});

test('accepts webhook with valid hmac signature', function () {
    $job = TranscodeJob::factory()->create(['status' => 'processing']);
    $payload = webhookPayload($job);

    $this->postJson(route('webhooks.transcode'), $payload, ['X-Signature' => signedRequest($payload)])
        ->assertOk();
});

test('returns 403 for missing signature', function () {
    $job = TranscodeJob::factory()->create(['status' => 'processing']);

    $this->postJson(route('webhooks.transcode'), webhookPayload($job))
        ->assertForbidden();
});

test('returns 403 for invalid hmac signature', function () {
    $job = TranscodeJob::factory()->create(['status' => 'processing']);

    $this->postJson(route('webhooks.transcode'), webhookPayload($job), ['X-Signature' => 'sha256=invalid'])
        ->assertForbidden();
});

test('updates job status to completed on success callback', function () {
    $job = TranscodeJob::factory()->create(['status' => 'processing']);
    $payload = webhookPayload($job);

    $this->postJson(route('webhooks.transcode'), $payload, ['X-Signature' => signedRequest($payload)]);

    expect($job->fresh()->status)->toBe(TranscodeStatus::Completed);
    expect($job->fresh()->worker_id)->toBe('go-worker-pod-abc123');
    expect($job->fresh()->output_path)->not->toBeNull();
});

test('updates job status to failed on error callback', function () {
    $job = TranscodeJob::factory()->create(['status' => 'processing']);
    $payload = webhookPayload($job, 'failed');

    $this->postJson(route('webhooks.transcode'), $payload, ['X-Signature' => signedRequest($payload)]);

    expect($job->fresh()->status)->toBe(TranscodeStatus::Failed);
    expect($job->fresh()->error_message)->toBe('FFmpeg crashed');
});

test('marks video completed when all jobs succeed', function () {
    $video = Video::factory()->create(['status' => 'processing']);
    $job = TranscodeJob::factory()->create(['video_id' => $video->id, 'status' => 'processing']);
    $payload = webhookPayload($job);

    $this->postJson(route('webhooks.transcode'), $payload, ['X-Signature' => signedRequest($payload)]);

    expect($video->fresh()->status)->toBe(TranscodeStatus::Completed);
});

test('marks video failed when all jobs fail', function () {
    $video = Video::factory()->create(['status' => 'processing']);
    $job = TranscodeJob::factory()->create(['video_id' => $video->id, 'status' => 'processing']);
    $payload = webhookPayload($job, 'failed');

    $this->postJson(route('webhooks.transcode'), $payload, ['X-Signature' => signedRequest($payload)]);

    expect($video->fresh()->status)->toBe(TranscodeStatus::Failed);
});

test('does not change video status while other jobs are still processing', function () {
    $video = Video::factory()->create(['status' => 'processing']);
    $job1 = TranscodeJob::factory()->create(['video_id' => $video->id, 'status' => 'processing']);
    TranscodeJob::factory()->create(['video_id' => $video->id, 'status' => 'processing']);
    $payload = webhookPayload($job1);

    $this->postJson(route('webhooks.transcode'), $payload, ['X-Signature' => signedRequest($payload)]);

    expect($video->fresh()->status)->toBe(TranscodeStatus::Processing);
});

test('returns 200 and no-ops for duplicate callback on settled job', function () {
    $job = TranscodeJob::factory()->completed()->create();
    $originalPath = $job->output_path;
    $payload = webhookPayload($job);

    $this->postJson(route('webhooks.transcode'), $payload, ['X-Signature' => signedRequest($payload)])
        ->assertOk();

    expect($job->fresh()->output_path)->toBe($originalPath);
});

test('returns 200 with no-op for unknown job_uuid', function () {
    $payload = ['job_uuid' => 'non-existent-uuid', 'status' => 'completed', 'outputs' => [], 'error_message' => ''];

    $this->postJson(route('webhooks.transcode'), $payload, ['X-Signature' => signedRequest($payload)])
        ->assertOk();
});

test('returns 422 for unrecognised status value in webhook payload', function () {
    $job = TranscodeJob::factory()->create(['status' => 'processing']);
    $payload = array_merge(webhookPayload($job), ['status' => 'bogus-status']);

    $this->postJson(route('webhooks.transcode'), $payload, ['X-Signature' => signedRequest($payload)])
        ->assertUnprocessable();
});

test('returns 422 for invalid status transition in webhook payload', function () {
    $job = TranscodeJob::factory()->create(['status' => 'queued']);
    $payload = webhookPayload($job, 'completed');

    $this->postJson(route('webhooks.transcode'), $payload, ['X-Signature' => signedRequest($payload)])
        ->assertUnprocessable();
});

test('fires TranscodeCompleted event on success', function () {
    $job = TranscodeJob::factory()->create(['status' => 'processing']);
    $payload = webhookPayload($job);

    $this->postJson(route('webhooks.transcode'), $payload, ['X-Signature' => signedRequest($payload)]);

    Event::assertDispatched(TranscodeCompleted::class);
});

test('does not fire TranscodeCompleted event on failure', function () {
    $job = TranscodeJob::factory()->create(['status' => 'processing']);
    $payload = webhookPayload($job, 'failed');

    $this->postJson(route('webhooks.transcode'), $payload, ['X-Signature' => signedRequest($payload)]);

    Event::assertNotDispatched(TranscodeCompleted::class);
});

<?php

namespace App\Services;

use App\Events\TranscodeCompleted;
use App\Models\TranscodeJob;
use App\Models\Video;

class WebhookService
{
    public function process(array $payload): void
    {
        $job = TranscodeJob::where('job_uuid', $payload['job_uuid'])->first();

        if (! $job) {
            return;
        }

        // Idempotency: ignore duplicate callbacks for already-settled jobs
        if (in_array($job->status, ['completed', 'failed'])) {
            return;
        }

        $isSuccess = $payload['status'] === 'completed';

        $job->update([
            'status' => $payload['status'],
            'worker_id' => $payload['worker_id'] ?? null,
            'error_message' => $payload['error_message'] ?: null,
            'started_at' => $payload['started_at'] ?? null,
            'completed_at' => $payload['completed_at'] ?? null,
            'output_path' => $isSuccess ? $this->extractOutputPath($payload) : null,
        ]);

        $video = $job->video;

        $this->updateVideoStatus($video);

        if ($isSuccess) {
            TranscodeCompleted::dispatch($video->fresh(), $job->fresh());
        }
    }

    private function updateVideoStatus(Video $video): void
    {
        $video->load('transcodeJobs');

        $statuses = $video->transcodeJobs->pluck('status');

        if ($statuses->every(fn ($s) => $s === 'completed')) {
            $video->update(['status' => 'completed']);
        } elseif ($statuses->contains('failed') && $statuses->doesntContain('pending') && $statuses->doesntContain('queued') && $statuses->doesntContain('processing')) {
            $video->update(['status' => 'failed']);
        }
    }

    private function extractOutputPath(array $payload): ?string
    {
        $outputs = $payload['outputs'] ?? [];

        return ! empty($outputs) ? ($outputs[0]['output_key'] ?? null) : null;
    }
}

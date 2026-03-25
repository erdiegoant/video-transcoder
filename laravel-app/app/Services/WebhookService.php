<?php

namespace App\Services;

use App\Enums\TranscodeStatus;
use App\Events\TranscodeCompleted;
use App\Models\TranscodeJob;
use App\Models\Video;

class WebhookService
{
    public function process(array $payload): void
    {
        $job = TranscodeJob::where('job_uuid', $payload['job_uuid'] ?? '')->first();

        if (! $job) {
            return;
        }

        // Idempotency: ignore duplicate callbacks for already-settled jobs
        if ($job->status->isTerminal()) {
            return;
        }

        $newStatus = TranscodeStatus::tryFrom($payload['status'] ?? '');

        abort_if(
            $newStatus === null || ! $job->status->canTransitionTo($newStatus),
            422,
            'Invalid status transition.'
        );

        $isSuccess = $newStatus === TranscodeStatus::Completed;

        $job->update([
            'status' => $newStatus,
            'worker_id' => $payload['worker_id'] ?? null,
            'error_message' => ($payload['error_message'] ?? null) ?: null,
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

        if ($statuses->every(fn (TranscodeStatus $s) => $s === TranscodeStatus::Completed)) {
            $video->update(['status' => TranscodeStatus::Completed]);
        } elseif ($statuses->contains(TranscodeStatus::Failed)
            && $statuses->doesntContain(TranscodeStatus::Pending)
            && $statuses->doesntContain(TranscodeStatus::Queued)
            && $statuses->doesntContain(TranscodeStatus::Processing)) {
            $video->update(['status' => TranscodeStatus::Failed]);
        }
    }

    private function extractOutputPath(array $payload): ?string
    {
        $outputs = $payload['outputs'] ?? [];

        return ! empty($outputs) ? ($outputs[0]['output_key'] ?? null) : null;
    }
}

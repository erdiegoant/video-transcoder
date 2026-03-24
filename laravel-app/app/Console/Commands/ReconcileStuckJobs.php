<?php

namespace App\Console\Commands;

use App\Models\TranscodeJob;
use App\Models\Video;
use App\Services\TranscodeJobService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

#[Signature('transcode:reconcile')]
#[Description('Requeue or fail transcode jobs stuck in the processing state for more than 30 minutes')]
class ReconcileStuckJobs extends Command
{
    public function handle(TranscodeJobService $jobService): int
    {
        $staleThreshold = now()->subMinutes(30);

        $stuckJobs = TranscodeJob::query()
            ->where('status', 'processing')
            ->where('updated_at', '<', $staleThreshold)
            ->with('video')
            ->get();

        if ($stuckJobs->isEmpty()) {
            $this->info('No stuck jobs found.');

            return self::SUCCESS;
        }

        $this->info("Found {$stuckJobs->count()} stuck job(s).");

        foreach ($stuckJobs as $job) {
            if ($job->isRetryable()) {
                $job->increment('attempts');
                $job->update(['status' => 'queued']);

                Redis::rpush('queue:transcode', json_encode($jobService->buildSingleJobPayload($job)));

                $this->line("  Requeued {$job->job_uuid} (attempt {$job->attempts}/{$job->max_attempts})");
            } else {
                $job->update([
                    'status' => 'failed',
                    'error_message' => 'Job timed out and exhausted all retry attempts.',
                ]);

                $this->updateVideoStatus($job->video->fresh());

                $this->line("  Failed {$job->job_uuid} (max attempts reached)");
            }
        }

        return self::SUCCESS;
    }

    private function updateVideoStatus(Video $video): void
    {
        $video->load('transcodeJobs');

        $statuses = $video->transcodeJobs->pluck('status');

        if ($statuses->contains('failed') && $statuses->doesntContain('pending') && $statuses->doesntContain('queued') && $statuses->doesntContain('processing')) {
            $video->update(['status' => 'failed']);
        }
    }
}

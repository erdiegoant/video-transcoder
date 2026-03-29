<?php

namespace App\Jobs;

use App\Models\Video;
use App\Services\TranscodeJobService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class DispatchTranscodeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Video $video) {}

    public function handle(TranscodeJobService $service): void
    {
        $this->video->loadMissing('transcodeJobs');

        // Push one Redis message per TranscodeJob so every job has its own UUID,
        // can fail/succeed independently, and maps cleanly to a single callback.
        foreach ($this->video->transcodeJobs as $job) {
            Log::info("Dispatching transcode job {$job->id}");
            Redis::rpush('queue:transcode', json_encode($service->buildSingleJobPayload($job)));
        }
    }
}

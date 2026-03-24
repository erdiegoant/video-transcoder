<?php

namespace App\Jobs;

use App\Models\Video;
use App\Services\TranscodeJobService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Redis;

class DispatchTranscodeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Video $video) {}

    public function handle(TranscodeJobService $service): void
    {
        $payload = $service->buildPayload($this->video);

        // Push raw JSON to Redis so the Go worker can consume it directly.
        // We do NOT use Laravel's serialized queue format here — Go reads plain JSON.
        Redis::rpush('queue:transcode', json_encode($payload));
    }
}

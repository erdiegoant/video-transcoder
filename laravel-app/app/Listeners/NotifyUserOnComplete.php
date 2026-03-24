<?php

namespace App\Listeners;

use App\Events\TranscodeCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifyUserOnComplete implements ShouldQueue
{
    public function handle(TranscodeCompleted $event): void
    {
        // TODO: send email/notification to user when their video finishes processing
        Log::info('Transcode completed', [
            'video_id' => $event->video->id,
            'job_uuid' => $event->job->job_uuid,
            'user_id' => $event->video->user_id,
        ]);
    }
}

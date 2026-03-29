<?php

namespace App\Events;

use App\Models\TranscodeJob;
use App\Models\Video;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TranscodeJobStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Video $video,
        public readonly TranscodeJob $job,
    ) {}

    /** @return Channel[] */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('App.Models.User.'.$this->video->user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'TranscodeJobStatusUpdated';
    }
}

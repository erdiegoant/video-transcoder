<?php

namespace App\Events;

use App\Models\TranscodeJob;
use App\Models\Video;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TranscodeCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Video $video,
        public readonly TranscodeJob $job,
    ) {}
}

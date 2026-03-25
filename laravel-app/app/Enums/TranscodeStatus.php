<?php

namespace App\Enums;

enum TranscodeStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending => $next === self::Queued,
            self::Queued => $next === self::Processing,
            self::Processing => in_array($next, [self::Completed, self::Failed, self::Queued], true),
            self::Completed, self::Failed => false,
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed => true,
            default => false,
        };
    }
}

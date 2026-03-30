<?php

namespace App\Enums;

enum SubscriptionTier: string
{
    case Free = 'free';
    case Pro = 'pro';
    case Enterprise = 'enterprise';

    public function label(): string
    {
        return match ($this) {
            self::Free => 'Free',
            self::Pro => 'Pro',
            self::Enterprise => 'Enterprise',
        };
    }

    public function storageLimitBytes(): int
    {
        return match ($this) {
            self::Free => 524_288_000,          // 500 MB
            self::Pro => 5_368_709_120,         // 5 GB
            self::Enterprise => 53_687_091_200, // 50 GB
        };
    }

    public function storageLimitLabel(): string
    {
        return match ($this) {
            self::Free => '500 MB',
            self::Pro => '5 GB',
            self::Enterprise => '50 GB',
        };
    }

    public function monthlyUploadLimit(): int
    {
        return match ($this) {
            self::Free => 20,
            self::Pro => 200,
            self::Enterprise => 2000,
        };
    }
}

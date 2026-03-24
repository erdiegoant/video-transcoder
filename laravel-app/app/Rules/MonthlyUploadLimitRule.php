<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Auth;

class MonthlyUploadLimitRule implements ValidationRule
{
    public function __construct(private readonly int $monthlyLimit = 20) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $user = Auth::user();

        if ($user->monthly_upload_count >= $this->monthlyLimit) {
            $fail("You have reached your monthly upload limit of {$this->monthlyLimit} videos.");
        }
    }
}

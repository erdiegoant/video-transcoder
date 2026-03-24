<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;

class UserStorageQuotaRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }

        $user = Auth::user();

        if (! $user->hasStorageAvailable($value->getSize())) {
            $fail('You have exceeded your storage quota. Please delete some videos to free up space.');
        }
    }
}

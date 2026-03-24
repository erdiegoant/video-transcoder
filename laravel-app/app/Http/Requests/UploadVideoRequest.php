<?php

namespace App\Http\Requests;

use App\Rules\MonthlyUploadLimitRule;
use App\Rules\UserStorageQuotaRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UploadVideoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:mp4,mov,avi,webm',
                'max:512000',
                new UserStorageQuotaRule,
                new MonthlyUploadLimitRule,
            ],
            'operations' => ['required', 'array', 'min:1'],
            'operations.*.type' => ['required', 'string', 'in:transcode,trim,thumbnail'],
            'operations.*.format' => ['nullable', 'string', 'in:mp4,webm,gif,jpg'],
            'operations.*.resolution' => ['nullable', 'string', 'regex:/^\d+x\d+$/'],
            'operations.*.trim_start_sec' => ['nullable', 'numeric', 'min:0'],
            'operations.*.trim_end_sec' => ['nullable', 'numeric', 'gt:operations.*.trim_start_sec'],
            'operations.*.thumbnail_at_sec' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimes' => 'Only MP4, MOV, AVI, and WebM videos are supported.',
            'file.max' => 'Videos must be smaller than 500 MB.',
            'operations.*.type.in' => 'Each operation must be one of: transcode, trim, or thumbnail.',
        ];
    }
}

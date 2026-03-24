<?php

namespace App\Models;

use Database\Factories\TranscodeJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'video_id',
    'job_uuid',
    'operation_type',
    'target_format',
    'target_resolution',
    'trim_start_sec',
    'trim_end_sec',
    'thumbnail_at_sec',
    'output_path',
    'status',
    'attempts',
    'max_attempts',
    'worker_id',
    'error_message',
    'started_at',
    'completed_at',
])]
class TranscodeJob extends Model
{
    /** @use HasFactory<TranscodeJobFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'trim_start_sec' => 'float',
            'trim_end_sec' => 'float',
            'thumbnail_at_sec' => 'float',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function isRetryable(): bool
    {
        return $this->attempts < $this->max_attempts;
    }
}

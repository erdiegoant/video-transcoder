<?php

namespace App\Services;

use App\Models\TranscodeJob;

class TranscodeJobService
{
    public function buildSingleJobPayload(TranscodeJob $job): array
    {
        $video = $job->video;
        $outputKeyPrefix = "outputs/users/{$video->user_id}/{$video->uuid}/";

        return [
            'job_uuid' => $job->job_uuid,
            'video_id' => $video->id,
            'user_id' => $video->user_id,
            'source_bucket' => config('filesystems.disks.uploads.bucket'),
            'source_key' => $video->storage_path,
            'output_bucket' => config('filesystems.disks.outputs.bucket'),
            'output_key_prefix' => $outputKeyPrefix,
            'operations' => [$this->buildOperation($job)],
            'callback_url' => config('services.transcoder.callback_url'),
            'max_attempts' => $job->max_attempts,
            'enqueued_at' => now()->toIso8601String(),
        ];
    }

    private function buildOperation(TranscodeJob $job): array
    {
        $operation = ['type' => $job->operation_type];

        return match ($job->operation_type) {
            'transcode' => array_merge(
                $operation,
                [
                    'format' => $job->target_format,
                    'resolution' => $job->target_resolution,
                    'crf' => 28,
                ],
                $job->trim_start_sec !== null ? ['trim_start' => $job->trim_start_sec] : [],
                $job->trim_end_sec !== null ? ['trim_end' => $job->trim_end_sec] : [],
            ),
            'thumbnail' => array_merge($operation, [
                'at_second' => $job->thumbnail_at_sec ?? 3.0,
                'format' => $job->target_format ?? 'jpg',
            ]),
            'trim' => array_merge($operation, [
                'trim_start' => $job->trim_start_sec,
                'trim_end' => $job->trim_end_sec,
            ]),
            default => $operation,
        };
    }
}

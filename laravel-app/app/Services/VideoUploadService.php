<?php

namespace App\Services;

use App\Enums\TranscodeStatus;
use App\Jobs\DispatchTranscodeJob;
use App\Models\TranscodeJob;
use App\Models\User;
use App\Models\Video;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VideoUploadService
{
    public function upload(User $user, UploadedFile $file, array $operations): Video
    {
        $uuid = Str::uuid()->toString();
        $ext = $file->getClientOriginalExtension();
        $storagePath = "uploads/users/{$user->id}/{$uuid}.{$ext}";
        $contentHash = hash_file('sha256', $file->getRealPath());

        if ($existingVideo = $this->findDuplicate($user, $contentHash)) {
            return $existingVideo;
        }

        Storage::disk('uploads')->putFileAs("uploads/users/{$user->id}", $file, "{$uuid}.{$ext}");

        return DB::transaction(function () use ($user, $file, $uuid, $storagePath, $contentHash, $operations) {
            $video = Video::create([
                'user_id' => $user->id,
                'uuid' => $uuid,
                'original_filename' => $file->getClientOriginalName(),
                'storage_path' => $storagePath,
                'file_size_bytes' => $file->getSize(),
                'content_hash' => $contentHash,
                'mime_type' => $file->getMimeType(),
                'status' => TranscodeStatus::Pending,
            ]);

            $user->increment('storage_used_bytes', $file->getSize());
            $user->increment('monthly_upload_count');

            foreach ($operations as $operation) {
                TranscodeJob::create([
                    'video_id' => $video->id,
                    'job_uuid' => Str::uuid()->toString(),
                    'operation_type' => $operation['type'],
                    'target_format' => $operation['format'] ?? null,
                    'target_resolution' => $operation['resolution'] ?? null,
                    'trim_start_sec' => $operation['trim_start_sec'] ?? null,
                    'trim_end_sec' => $operation['trim_end_sec'] ?? null,
                    'thumbnail_at_sec' => $operation['thumbnail_at_sec'] ?? null,
                    'status' => TranscodeStatus::Pending,
                ]);
            }

            $video->update(['status' => TranscodeStatus::Queued]);
            $video->transcodeJobs()->update(['status' => TranscodeStatus::Queued->value]);

            DispatchTranscodeJob::dispatch($video->fresh(['transcodeJobs']));

            return $video;
        });
    }

    private function findDuplicate(User $user, string $contentHash): ?Video
    {
        return $user->videos()
            ->where('content_hash', $contentHash)
            ->where('status', '!=', TranscodeStatus::Failed)
            ->first();
    }
}

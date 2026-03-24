<?php

namespace App\Console\Commands;

use App\Models\Video;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('videos:prune')]
#[Description('Hard-delete videos soft-deleted over 30 days ago, remove their files from storage, and decrement user storage counters')]
class PruneExpiredVideos extends Command
{
    public function handle(): int
    {
        $expiredVideos = Video::onlyTrashed()
            ->where('deleted_at', '<', now()->subDays(30))
            ->with(['user', 'transcodeJobs'])
            ->get();

        if ($expiredVideos->isEmpty()) {
            $this->info('No expired videos to prune.');

            return self::SUCCESS;
        }

        $this->info("Pruning {$expiredVideos->count()} expired video(s).");

        foreach ($expiredVideos as $video) {
            $this->deleteStorageFiles($video);

            $video->user?->decrement('storage_used_bytes', $video->file_size_bytes);

            $video->forceDelete();

            $this->line("  Deleted video {$video->uuid} ({$video->original_filename})");
        }

        return self::SUCCESS;
    }

    private function deleteStorageFiles(Video $video): void
    {
        try {
            Storage::disk('uploads')->delete($video->storage_path);
        } catch (\Throwable) {
            $this->warn("  Could not delete upload file for video {$video->uuid}");
        }

        foreach ($video->transcodeJobs as $job) {
            if (! $job->output_path) {
                continue;
            }

            try {
                Storage::disk('outputs')->delete($job->output_path);
            } catch (\Throwable) {
                $this->warn("  Could not delete output file for job {$job->job_uuid}");
            }
        }
    }
}

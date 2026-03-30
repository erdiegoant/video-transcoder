<?php

use App\Models\Video;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public int $userId;

    public function mount(): void
    {
        $this->userId = Auth::id();
    }

    #[Computed]
    public function videos(): \Illuminate\Pagination\LengthAwarePaginator
    {
        return Auth::user()
            ->videos()
            ->with('transcodeJobs')
            ->latest()
            ->paginate(10);
    }

    #[Computed]
    public function storageUsedBytes(): int
    {
        return Auth::user()->storage_used_bytes ?? 0;
    }

    #[Computed]
    public function storageLimitBytes(): int
    {
        return Auth::user()->storage_limit_bytes ?? 524_288_000;
    }

    #[Computed]
    public function storagePercent(): int
    {
        if ($this->storageLimitBytes === 0) {
            return 0;
        }

        return (int) min(100, round(($this->storageUsedBytes / $this->storageLimitBytes) * 100));
    }

    #[On('video-uploaded')]
    #[On('echo-private:App.Models.User.{userId},.TranscodeJobStatusUpdated')]
    public function refresh(): void
    {
        unset($this->videos);
        $this->resetPage();
    }

    public function deleteVideo(int $videoId): void
    {
        $video = Video::findOrFail($videoId);

        $this->authorize('delete', $video);

        $video->delete();

        $this->resetPage();
    }
}; ?>

<div>
    <div class="mb-6 flex items-center justify-between">
        <div>
            <flux:heading size="lg" class="mb-1">Your Videos</flux:heading>
            <flux:subheading>{{ $this->videos->total() }} {{ Str::plural('video', $this->videos->total()) }}</flux:subheading>
        </div>

        {{-- Storage usage --}}
        <div class="hidden w-48 sm:block">
            <div class="mb-1 flex justify-between text-xs text-zinc-500 dark:text-zinc-400">
                <span>Storage</span>
                <span>{{ number_format($this->storageUsedBytes / 1_048_576, 0) }} / {{ number_format($this->storageLimitBytes / 1_048_576, 0) }} MB</span>
            </div>
            <div class="h-1.5 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                <div
                    class="h-1.5 rounded-full transition-all {{ $this->storagePercent >= 90 ? 'bg-red-500' : ($this->storagePercent >= 70 ? 'bg-amber-500' : 'bg-blue-500') }}"
                    style="width: {{ $this->storagePercent }}%"
                ></div>
            </div>
        </div>
    </div>

    @if ($this->videos->isEmpty())
        <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-zinc-300 py-16 text-center dark:border-zinc-700">
            <flux:icon.film class="mb-3 size-10 text-zinc-300 dark:text-zinc-600" />
            <flux:text class="font-medium text-zinc-500">No videos yet</flux:text>
            <flux:text class="mt-1 text-sm text-zinc-400">Upload your first video to get started.</flux:text>
        </div>
    @else
        <div class="space-y-3">
            @foreach ($this->videos as $video)
                @php
                    $statusColor = match($video->status->value) {
                        'queued'     => 'blue',
                        'processing' => 'yellow',
                        'completed'  => 'green',
                        'failed'     => 'red',
                        default      => 'zinc',
                    };
                @endphp

                <div wire:key="video-{{ $video->id }}" class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <flux:text class="truncate font-medium">{{ $video->original_filename }}</flux:text>
                                <flux:badge color="{{ $statusColor }}" size="sm">{{ ucfirst($video->status->value) }}</flux:badge>
                            </div>
                            <flux:text class="mt-0.5 text-sm text-zinc-500">
                                {{ number_format($video->file_size_bytes / 1_048_576, 1) }} MB
                                · {{ $video->created_at->diffForHumans() }}
                            </flux:text>

                            @if ($video->error_message)
                                <flux:text class="mt-1 text-sm text-red-500">{{ $video->error_message }}</flux:text>
                            @endif
                        </div>

                        <flux:button
                            wire:click="deleteVideo({{ $video->id }})"
                            wire:confirm="Delete this video?"
                            variant="ghost"
                            size="sm"
                            icon="trash"
                            class="shrink-0 text-zinc-400 hover:text-red-500"
                        />
                    </div>

                    @if ($video->transcodeJobs->isNotEmpty())
                        <div class="mt-3 space-y-1.5 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                            @foreach ($video->transcodeJobs as $job)
                                @php
                                    $jobStatusColor = match($job->status->value) {
                                        'queued'     => 'blue',
                                        'processing' => 'yellow',
                                        'completed'  => 'green',
                                        'failed'     => 'red',
                                        default      => 'zinc',
                                    };
                                @endphp

                                <div wire:key="job-{{ $job->id }}" class="flex items-center justify-between gap-2">
                                    <div class="flex items-center gap-2">
                                        <flux:text class="text-sm text-zinc-500">
                                            {{ ucfirst($job->operation_type) }}
                                            @if ($job->target_format) · {{ strtoupper($job->target_format) }} @endif
                                            @if ($job->target_resolution) · {{ $job->target_resolution }} @endif
                                            @if ($job->operation_type === 'thumbnail') · {{ $job->thumbnail_at_sec }}s @endif
                                        </flux:text>
                                        <flux:badge color="{{ $jobStatusColor }}" size="sm">{{ ucfirst($job->status->value) }}</flux:badge>
                                    </div>

                                    @if ($job->status->value === 'completed' && $job->output_path)
                                        <a href="{{ route('videos.download', [$video, $job]) }}" target="_blank">
                                            <flux:button variant="ghost" size="sm" icon="arrow-down-tray">
                                                Download
                                            </flux:button>
                                        </a>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="mt-4">
            {{ $this->videos->links() }}
        </div>
    @endif
</div>

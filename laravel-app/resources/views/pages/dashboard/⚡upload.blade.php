<?php

use App\Services\VideoUploadService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public mixed $video = null;

    public string $targetResolution = '1280x720';

    public bool $includeThumbnail = true;

    public bool $includeTrim = false;

    public string $trimStart = '0';

    public string $trimEnd = '';

    public function transcode(VideoUploadService $uploadService): void
    {
        $this->validate([
            'video' => 'required|file|mimes:mp4,mov,avi,webm|max:512000',
        ]);

        if ($this->includeTrim && $this->trimEnd !== '' && (float) $this->trimEnd <= (float) $this->trimStart) {
            $this->addError('trimEnd', 'End time must be greater than start time.');

            return;
        }

        $operations = [
            ['type' => 'transcode', 'format' => 'mp4', 'resolution' => $this->targetResolution],
        ];

        if ($this->includeThumbnail) {
            $operations[] = ['type' => 'thumbnail', 'thumbnail_at_sec' => 3.0];
        }

        if ($this->includeTrim) {
            $op = ['type' => 'trim', 'trim_start_sec' => (float) $this->trimStart];
            if ($this->trimEnd !== '') {
                $op['trim_end_sec'] = (float) $this->trimEnd;
            }
            $operations[] = $op;
        }

        try {
            $uploadService->upload(Auth::user(), $this->video, $operations);
            $this->video = null;
            $this->dispatch('video-uploaded');
        } catch (\Throwable) {
            $this->addError('video', 'Upload failed. Please check your connection and try again.');
        }
    }
}; ?>

<div>
    <flux:heading size="lg" class="mb-1">Upload Video</flux:heading>
    <flux:subheading class="mb-6">Upload a video to transcode it to a different format or resolution.</flux:subheading>

    <form wire:submit="transcode" class="space-y-5">
        <flux:field>
            <flux:label>Video file</flux:label>

            <div
                x-data="{ dragging: false }"
                x-on:dragover.prevent="dragging = true"
                x-on:dragleave.prevent="dragging = false"
                x-on:drop.prevent="
                    dragging = false;
                    if ($event.dataTransfer.files.length) {
                        $refs.fileInput.files = $event.dataTransfer.files;
                        $refs.fileInput.dispatchEvent(new Event('change'));
                    }
                "
                x-on:click="$refs.fileInput.click()"
                :class="dragging ? 'border-zinc-500 bg-zinc-50 dark:bg-zinc-800' : 'border-zinc-300 dark:border-zinc-700'"
                class="flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed p-10 text-center transition-colors select-none"
            >
                <flux:icon.arrow-up-tray class="mb-3 size-8 text-zinc-400" />

                @if ($video)
                    <flux:text class="font-medium text-green-600 dark:text-green-400">
                        {{ $video->getClientOriginalName() }}
                    </flux:text>
                    <flux:text class="mt-1 text-sm text-zinc-500">
                        {{ number_format($video->getSize() / 1_048_576, 1) }} MB · Click to change
                    </flux:text>
                @else
                    <flux:text class="font-medium">Drag & drop or click to browse</flux:text>
                    <flux:text class="mt-1 text-sm text-zinc-500">MP4, MOV, AVI, WebM · max 500 MB</flux:text>
                @endif

                <div wire:loading wire:target="video" class="mt-3 text-sm text-zinc-500">
                    Uploading…
                </div>

                <input
                    x-ref="fileInput"
                    type="file"
                    accept="video/mp4,video/quicktime,video/x-msvideo,video/webm"
                    wire:model="video"
                    class="hidden"
                    x-on:click.stop
                />
            </div>

            <flux:error name="video" />
        </flux:field>

        <flux:field>
            <flux:label>Output resolution</flux:label>
            <flux:select wire:model="targetResolution">
                <flux:select.option value="1920x1080">1080p (1920×1080)</flux:select.option>
                <flux:select.option value="1280x720">720p (1280×720)</flux:select.option>
                <flux:select.option value="854x480">480p (854×480)</flux:select.option>
            </flux:select>
        </flux:field>

        <flux:field variant="inline">
            <flux:checkbox wire:model="includeThumbnail" id="thumbnail" />
            <flux:label for="thumbnail">Generate thumbnail at 3 seconds</flux:label>
        </flux:field>

        <div x-data>
            <flux:field variant="inline">
                <flux:checkbox wire:model="includeTrim" id="trim" x-model="includeTrim" />
                <flux:label for="trim">Trim video</flux:label>
            </flux:field>

            <div x-show="$wire.includeTrim" x-cloak class="mt-3 grid grid-cols-2 gap-3">
                <flux:field>
                    <flux:label>Start (seconds)</flux:label>
                    <flux:input type="number" wire:model="trimStart" min="0" step="0.1" placeholder="0" />
                </flux:field>
                <flux:field>
                    <flux:label>End (seconds, optional)</flux:label>
                    <flux:input type="number" wire:model="trimEnd" min="0" step="0.1" placeholder="e.g. 30" />
                    <flux:error name="trimEnd" />
                </flux:field>
            </div>
        </div>

        <flux:button
            type="submit"
            variant="primary"
            class="w-full"
            wire:loading.attr="disabled"
            wire:target="upload"
        >
            <span wire:loading.remove wire:target="upload">Upload & Transcode</span>
            <span wire:loading wire:target="upload">Processing…</span>
        </flux:button>
    </form>
</div>

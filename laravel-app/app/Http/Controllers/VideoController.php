<?php

namespace App\Http\Controllers;

use App\Http\Requests\UploadVideoRequest;
use App\Models\Video;
use App\Services\VideoUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class VideoController extends Controller
{
    public function __construct(private readonly VideoUploadService $uploadService) {}

    public function store(UploadVideoRequest $request): JsonResponse
    {
        $video = $this->uploadService->upload(
            $request->user(),
            $request->file('file'),
            $request->input('operations'),
        );

        return response()->json($video->load('transcodeJobs'), 201);
    }

    public function index(Request $request): JsonResponse
    {
        $videos = $request->user()
            ->videos()
            ->with('transcodeJobs')
            ->latest()
            ->paginate(15);

        return response()->json($videos);
    }

    public function show(Request $request, Video $video): JsonResponse
    {
        $this->authorize('view', $video);

        return response()->json($video->load('transcodeJobs'));
    }

    public function download(Request $request, Video $video, int $jobId): RedirectResponse
    {
        $this->authorize('view', $video);

        $job = $video->transcodeJobs()->where('id', $jobId)->where('status', 'completed')->firstOrFail();

        $url = Storage::disk('outputs')->temporaryUrl($job->output_path, now()->addMinutes(15));

        return redirect($url);
    }

    public function destroy(Request $request, Video $video): JsonResponse
    {
        $this->authorize('delete', $video);

        // Soft delete only — MinIO cleanup is handled by PruneExpiredVideos cron command
        $video->delete();

        return response()->json(null, 204);
    }
}

<?php

use App\Models\TranscodeJob;
use App\Models\User;
use App\Models\Video;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('authenticated user sees dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeLivewire('pages::dashboard.upload')
        ->assertSeeLivewire('pages::dashboard.video-list');
});

test('unauthenticated user is redirected to login', function () {
    $this->get(route('dashboard'))
        ->assertRedirect(route('login'));
});

test('video list shows user videos only', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    Video::factory()->create(['user_id' => $user->id, 'original_filename' => 'my-video.mp4']);
    Video::factory()->create(['user_id' => $otherUser->id, 'original_filename' => 'their-video.mp4']);

    Livewire::actingAs($user)
        ->test('pages::dashboard.video-list')
        ->assertSee('my-video.mp4')
        ->assertDontSee('their-video.mp4');
});

test('video list shows empty state when user has no videos', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::dashboard.video-list')
        ->assertSee('No videos yet');
});

test('video list shows processing status', function () {
    $user = User::factory()->create();

    Video::factory()->processing()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('pages::dashboard.video-list')
        ->assertSee('Processing');
});

test('upload component renders', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::dashboard.upload')
        ->assertSee('Upload & Transcode', false);
});

test('upload rejects invalid mime type', function () {
    $user = User::factory()->create();

    Storage::fake('uploads');

    Livewire::actingAs($user)
        ->test('pages::dashboard.upload')
        ->set('video', UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'))
        ->call('upload')
        ->assertHasErrors(['video']);
});

test('upload dispatches video-uploaded event on success', function () {
    $user = User::factory()->create();

    Storage::fake('uploads');
    Bus::fake();

    Livewire::actingAs($user)
        ->test('pages::dashboard.upload')
        ->set('video', UploadedFile::fake()->create('clip.mp4', 1024, 'video/mp4'))
        ->call('upload')
        ->assertDispatched('video-uploaded');
});

test('video-uploaded event causes video list to re-render', function () {
    $user = User::factory()->create();
    Video::factory()->create(['user_id' => $user->id, 'original_filename' => 'fresh.mp4']);

    $component = Livewire::actingAs($user)
        ->test('pages::dashboard.video-list')
        ->assertSee('fresh.mp4');

    $component->dispatch('video-uploaded')->assertOk();
});

test('delete soft-deletes video', function () {
    $user = User::factory()->create();
    $video = Video::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('pages::dashboard.video-list')
        ->call('deleteVideo', $video->id);

    expect($video->fresh()->deleted_at)->not->toBeNull();
});

test('delete is forbidden for another user video', function () {
    $user = User::factory()->create();
    $otherVideo = Video::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::dashboard.video-list')
        ->call('deleteVideo', $otherVideo->id)
        ->assertForbidden();
});

test('download route is forbidden for another user video', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $video = Video::factory()->completed()->create(['user_id' => $otherUser->id]);
    $job = TranscodeJob::factory()->completed()->create(['video_id' => $video->id]);

    $this->actingAs($user)
        ->get(route('videos.download', [$video, $job]))
        ->assertForbidden();
});

test('download route redirects to presigned url for own completed job', function () {
    $user = User::factory()->create();
    $video = Video::factory()->completed()->create(['user_id' => $user->id]);
    $job = TranscodeJob::factory()->completed()->create([
        'video_id' => $video->id,
        'output_path' => 'outputs/users/1/abc/video.mp4',
    ]);

    Storage::fake('outputs');
    Storage::disk('outputs')->put('outputs/users/1/abc/video.mp4', 'fake content');

    $this->actingAs($user)
        ->get(route('videos.download', [$video, $job]))
        ->assertRedirect();
});

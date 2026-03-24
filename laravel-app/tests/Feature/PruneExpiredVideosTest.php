<?php

use App\Models\TranscodeJob;
use App\Models\User;
use App\Models\Video;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('uploads');
    Storage::fake('outputs');
});

test('hard deletes videos soft-deleted over 30 days ago', function () {
    $video = Video::factory()->create(['deleted_at' => now()->subDays(31)]);

    $this->artisan('videos:prune')->assertSuccessful();

    $this->assertDatabaseMissing('videos', ['id' => $video->id]);
});

test('does not delete recently soft-deleted videos', function () {
    $video = Video::factory()->create(['deleted_at' => now()->subDays(5)]);

    $this->artisan('videos:prune')->assertSuccessful();

    $this->assertDatabaseHas('videos', ['id' => $video->id]);
});

test('does not delete videos that are not soft-deleted', function () {
    $video = Video::factory()->create(['deleted_at' => null]);

    $this->artisan('videos:prune')->assertSuccessful();

    $this->assertDatabaseHas('videos', ['id' => $video->id]);
});

test('decrements user storage_used_bytes', function () {
    $user = User::factory()->create(['storage_used_bytes' => 5_000_000]);
    $video = Video::factory()->create([
        'user_id' => $user->id,
        'file_size_bytes' => 2_000_000,
        'deleted_at' => now()->subDays(31),
    ]);

    $this->artisan('videos:prune')->assertSuccessful();

    expect($user->fresh()->storage_used_bytes)->toBe(3_000_000);
});

test('deletes upload file from storage', function () {
    $video = Video::factory()->create([
        'storage_path' => 'uploads/users/1/test.mp4',
        'deleted_at' => now()->subDays(31),
    ]);

    Storage::disk('uploads')->put('uploads/users/1/test.mp4', 'fake');

    $this->artisan('videos:prune')->assertSuccessful();

    Storage::disk('uploads')->assertMissing('uploads/users/1/test.mp4');
});

test('deletes output files for completed jobs', function () {
    $video = Video::factory()->completed()->create(['deleted_at' => now()->subDays(31)]);
    TranscodeJob::factory()->completed()->create([
        'video_id' => $video->id,
        'output_path' => 'outputs/users/1/abc/video.mp4',
    ]);

    Storage::disk('outputs')->put('outputs/users/1/abc/video.mp4', 'fake');

    $this->artisan('videos:prune')->assertSuccessful();

    Storage::disk('outputs')->assertMissing('outputs/users/1/abc/video.mp4');
});

test('outputs a message when no expired videos exist', function () {
    $this->artisan('videos:prune')
        ->expectsOutput('No expired videos to prune.')
        ->assertSuccessful();
});

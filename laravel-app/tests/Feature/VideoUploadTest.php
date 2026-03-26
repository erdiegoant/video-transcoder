<?php

use App\Jobs\DispatchTranscodeJob;
use App\Models\User;
use App\Models\Video;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('uploads');
    Bus::fake();
});

test('unauthenticated user cannot upload a video', function () {
    $this->postJson('/api/videos')->assertUnauthorized();
});

test('authenticated user can upload a valid video', function () {
    $user = User::factory()->create();
    $file = UploadedFile::fake()->create('test.mp4', 1024, 'video/mp4');

    $response = $this->actingAs($user)->postJson('/api/videos', [
        'file' => $file,
        'operations' => [
            ['type' => 'transcode', 'format' => 'mp4', 'resolution' => '1280x720'],
        ],
    ]);

    $response->assertStatus(201);
    $this->assertDatabaseHas('videos', ['user_id' => $user->id, 'status' => 'queued']);
    $this->assertDatabaseHas('transcode_jobs', ['operation_type' => 'transcode']);
    Bus::assertDispatched(DispatchTranscodeJob::class);
});

test('upload is rejected for unsupported mime type', function () {
    $user = User::factory()->create();
    $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

    $this->actingAs($user)->postJson('/api/videos', [
        'file' => $file,
        'operations' => [['type' => 'transcode', 'format' => 'mp4']],
    ])->assertUnprocessable()->assertJsonValidationErrors(['file']);
});

test('upload is rejected when storage quota is exceeded', function () {
    $user = User::factory()->create([
        'storage_used_bytes' => 500 * 1024 * 1024,
        'storage_limit_bytes' => 500 * 1024 * 1024,
    ]);
    $file = UploadedFile::fake()->create('video.mp4', 1024, 'video/mp4');

    $this->actingAs($user)->postJson('/api/videos', [
        'file' => $file,
        'operations' => [['type' => 'transcode', 'format' => 'mp4']],
    ])->assertUnprocessable()->assertJsonValidationErrors(['file']);
});

test('upload is rejected when monthly limit is reached', function () {
    $user = User::factory()->create(['monthly_upload_count' => 20]);
    $file = UploadedFile::fake()->create('video.mp4', 1024, 'video/mp4');

    $this->actingAs($user)->postJson('/api/videos', [
        'file' => $file,
        'operations' => [['type' => 'transcode', 'format' => 'mp4']],
    ])->assertUnprocessable()->assertJsonValidationErrors(['file']);
});

test('duplicate file upload returns existing video', function () {
    $user = User::factory()->create();
    $file = UploadedFile::fake()->create('video.mp4', 1024, 'video/mp4');

    $firstResponse = $this->actingAs($user)->postJson('/api/videos', [
        'file' => $file,
        'operations' => [['type' => 'transcode', 'format' => 'mp4']],
    ])->assertStatus(201);

    $secondResponse = $this->actingAs($user)->postJson('/api/videos', [
        'file' => $file,
        'operations' => [['type' => 'transcode', 'format' => 'mp4']],
    ])->assertStatus(201);

    expect($firstResponse->json('id'))->toBe($secondResponse->json('id'));
    expect(Video::where('user_id', $user->id)->count())->toBe(1);
});

test('storage_used_bytes is incremented after upload', function () {
    $user = User::factory()->create(['storage_used_bytes' => 0]);
    $file = UploadedFile::fake()->create('video.mp4', 1024, 'video/mp4');

    $this->actingAs($user)->postJson('/api/videos', [
        'file' => $file,
        'operations' => [['type' => 'transcode', 'format' => 'mp4']],
    ]);

    expect($user->fresh()->storage_used_bytes)->toBeGreaterThan(0);
});

test('upload endpoint returns 429 after exceeding rate limit', function () {
    $user = User::factory()->create();
    $payload = [
        'file' => UploadedFile::fake()->create('video.mp4', 1024, 'video/mp4'),
        'operations' => [['type' => 'transcode', 'format' => 'mp4']],
    ];

    for ($i = 0; $i < 10; $i++) {
        $this->actingAs($user)->postJson('/api/videos', $payload);
    }

    $this->actingAs($user)
        ->postJson('/api/videos', $payload)
        ->assertTooManyRequests();
});

test('authenticated user can list their videos', function () {
    $user = User::factory()->create();
    Video::factory()->count(3)->create(['user_id' => $user->id]);

    $this->actingAs($user)->getJson('/api/videos')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('user cannot view another users video', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $video = Video::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($other)->getJson("/api/videos/{$video->id}")->assertForbidden();
});

test('user can soft delete their own video', function () {
    $user = User::factory()->create();
    $video = Video::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->deleteJson("/api/videos/{$video->id}")->assertNoContent();

    $this->assertSoftDeleted('videos', ['id' => $video->id]);
});

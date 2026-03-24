# Step 1.1 — Laravel Foundation

**Goal:** Auth, file upload, DB records, job dispatch.

## Status: COMPLETE

All tasks below have been implemented and tested (61 tests passing).

---

## 1.1.a — Laravel Config

Laravel 13 + Livewire 4 + Flux UI 2 + Fortify + Pest are already installed in `laravel-app/`. Completed:

- [x] `composer require league/flysystem-aws-s3-v3` (MinIO uses the S3 API)
- [x] `composer require predis/predis` (Redis client)
- [x] `config/filesystems.php` — added `uploads` and `outputs` S3 disks with `use_path_style_endpoint: true`
- [x] `config/queue.php` — default queue name set to `transcode`
- [x] `config/services.php` — added `transcoder.webhook_secret` and `transcoder.callback_url`
- [x] `.env.example` updated with all new keys

## 1.1.b — Migrations

- [x] `add_subscription_fields_to_users_table` — subscription_tier, monthly_upload_count, monthly_upload_reset, storage_used_bytes, storage_limit_bytes (default 500MB)
- [x] `create_videos_table` — uuid, storage_path, content_hash (SHA-256, for dedup), mime_type, status, softDeletes
- [x] `create_transcode_jobs_table` — job_uuid, operation_type, target_format, target_resolution, trim/thumbnail fields, status, attempts, worker_id

**Note:** All migrations have empty `down()` methods — no rollback logic by design.

## 1.1.c — Models

- [x] `Video` — fillable, SoftDeletes, HasFactory; `user()` belongsTo, `transcodeJobs()` hasMany, `scopePending()`, `scopeByUser()`
- [x] `TranscodeJob` — fillable, HasFactory; `video()` belongsTo, `isRetryable()` helper
- [x] `User` — added HasApiTokens, `videos()` hasMany, `storageUsagePercent()`, `hasStorageAvailable(int $fileSizeBytes): bool`
- [x] `VideoFactory` — states: `queued()`, `processing()`, `completed()`, `failed()`
- [x] `TranscodeJobFactory` — states: `transcode()`, `thumbnail()`, `trim()`, `processing()`, `completed()`, `failed()`

## 1.1.d — Upload Endpoint

- [x] `UploadVideoRequest` — mimes: mp4/mov/avi/webm, max 500MB, `UserStorageQuotaRule`, `MonthlyUploadLimitRule`
- [x] `UserStorageQuotaRule` — checks `Auth::user()->hasStorageAvailable($value->getSize())`
- [x] `MonthlyUploadLimitRule` — readonly class, default limit 20/month
- [x] `VideoUploadService::upload()` — UUID generation, SHA-256 content hash, duplicate detection, `Storage::disk('uploads')`, DB transaction, increments counters, dispatches job
- [x] `VideoController` — store (201), index (paginated 15), show (with policy), download (presigned URL redirect, 15 min TTL), destroy (soft delete, 204)
- [x] `VideoPolicy` — view/delete check `$user->id === $video->user_id`
- [x] API routes under `auth:sanctum + verified`

## 1.1.e — Job Dispatch

- [x] `DispatchTranscodeJob` — pushes raw JSON to Redis via `Redis::rpush('queue:transcode', json_encode($payload))`
  - **Why not `dispatch()`?** Go reads raw JSON from Redis, not PHP-serialized payloads
- [x] `TranscodeJobService::buildPayload(Video $video): array` — assembles Redis payload matching the service contract

## Tests

- `tests/Feature/VideoUploadTest.php` — 9 tests
- `tests/Unit/TranscodeJobServiceTest.php` — 6 tests

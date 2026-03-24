# Reference — Edge Cases, Testing Strategy, Environment Variables, Go Notes

---

## Edge Cases & Mitigations

### Upload Layer (Laravel)

| Edge Case | Detection | Mitigation |
|---|---|---|
| Corrupted file (not actually a video) | PHP `finfo_file()` MIME sniff on bytes, not extension | Reject in `UploadVideoRequest` before saving |
| File exceeds PHP memory | `upload_max_filesize` / `post_max_size` in `php.ini` | Set to 512M in Docker; handle `UPLOAD_ERR_INI_SIZE` |
| User exceeds storage quota | Check before accepting upload | `UserStorageQuotaRule` in form request; return 422 |
| Monthly limit reached | Check count before accepting | `MonthlyUploadLimitRule` in form request |
| Duplicate file upload | SHA-256 hash comparison | Store `content_hash` on `videos` table; return existing video if hash matches |
| Upload interrupted (partial file) | PHP upload error codes | Check `$request->file('file')->isValid()` |
| Concurrent uploads hit storage limit | Race condition | DB transaction + pessimistic lock on `users.storage_used_bytes` |

### Queue & Worker (Go)

| Edge Case | Detection | Mitigation |
|---|---|---|
| FFmpeg crashes (non-zero exit) | `cmd.Wait()` returns error | Capture stderr, log it, mark job failed, increment attempts, NACK |
| FFmpeg timeout (huge file) | `context.WithTimeout` | Per-job timeout from payload; context cancels FFmpeg process |
| Pod killed mid-job (OOMKill/SIGKILL) | Job stays in processing list forever | `RecoverStale` goroutine requeues jobs older than 30min from `queue:transcode:processing` |
| Redis connection lost during `BLPOP` | `redis.Nil` error or connection reset | Retry loop with exponential backoff |
| MinIO unavailable for download | HTTP error | Retry 3x with 5s delay; fail job if all retries fail |
| Disk full in temp dir | FFmpeg writes partial file, exits non-zero | Pre-flight: `os.StatFS()` check before starting; require 3x source file size free |
| Output file collision (reprocessing) | Same output key written twice | Deterministic key: `outputs/users/{uid}/{job_uuid}/{operation}.{ext}` — always idempotent |
| Go worker OOM (huge 4K video) | Pod evicted by K8s | Set `resources.limits.memory = 1Gi`; FFmpeg streams rather than loads to memory |
| FFmpeg not in container | Worker starts but can't process | Readiness probe runs `ffmpeg -version`; pod never joins service until healthy |

### Callback Layer

| Edge Case | Detection | Mitigation |
|---|---|---|
| Laravel down at callback time | HTTP 5xx / connection refused | Retry with backoff (5s, 30s, 5min); `ReconcileStuckJobs` cron reconciles |
| Invalid HMAC signature | `hash_equals()` returns false | 403 response; log with source IP |
| Duplicate callback (retry scenario) | `job_uuid` already `completed` in DB | Return 200 immediately (idempotent) |
| Callback for deleted video | `video_id` not found | Return 404; Go logs but doesn't retry |
| Callback body too large | Laravel request size limit | Set `MAX_CONTENT_LENGTH` — callback payloads should be <10KB |

### Subscription & Business Logic

| Edge Case | Detection | Mitigation |
|---|---|---|
| Subscription expires mid-processing | Check at job dispatch time | Job already in queue is processed; subscription checked only at upload time |
| Admin changes user's tier | Storage limit changes | Enforce new limit on next upload, not retroactively |
| Storage counter drifts (race condition) | Periodic audit finds mismatch | `RecalculateStorageCommand` that sums actual MinIO objects vs DB value |

---

## Testing Strategy

### Laravel (Pest)

**Unit Tests** — no DB, no HTTP, mock all external deps:

```php
// tests/Unit/VideoUploadServiceTest.php
it('generates correct storage path for given user and uuid')
it('throws exception when storage_used_bytes exceeds limit')
it('increments storage_used_bytes after successful upload')
it('creates transcode job records for each requested operation')
it('rolls back video record if MinIO upload fails')

// tests/Unit/TranscodeJobServiceTest.php
it('builds valid redis payload matching the go worker contract')
it('includes all operations in payload')
it('sets correct callback url from config')
```

**Feature Tests** — uses DB (SQLite in-memory), mocks MinIO and Redis:

```php
// Fake storage so tests don't hit real MinIO
Storage::fake('s3');
Queue::fake();
Bus::fake();
Event::fake();
Mail::fake();

// Use factories
$user = User::factory()->create(['subscription_tier' => 'free', 'storage_used_bytes' => 500 * 1024 * 1024 - 1]);
$file = UploadedFile::fake()->create('video.mp4', 1024, 'video/mp4');
```

### Go (standard `testing` + `testify`)

Use `miniredis` for Redis integration tests. Use `testing/synctest` (Go 1.25) for backoff logic tests — advances fake time so 30-second retries test instantly.

---

## Environment Variables Reference

### Laravel `.env`

```ini
APP_NAME=VideoTrimmer
APP_ENV=local
APP_KEY=                          # php artisan key:generate

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=videotrimmer
DB_USERNAME=dev
DB_PASSWORD=secret

QUEUE_CONNECTION=redis
REDIS_CLIENT=predis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_QUEUE=transcode

FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=minioadmin
AWS_SECRET_ACCESS_KEY=minioadmin
AWS_DEFAULT_REGION=us-east-1
AWS_UPLOADS_BUCKET=videotrimmer-uploads
AWS_OUTPUTS_BUCKET=videotrimmer-outputs
AWS_ENDPOINT=http://minio:9000
AWS_USE_PATH_STYLE_ENDPOINT=true

TRANSCODER_WEBHOOK_SECRET=change-me-in-production
TRANSCODER_CALLBACK_URL=http://laravel/webhooks/transcode

MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
```

### Go Worker `.env` (injected as Docker env vars)

```ini
REDIS_URL=redis:6379
REDIS_QUEUE=queue:transcode

MINIO_ENDPOINT=minio:9000
MINIO_ACCESS_KEY=minioadmin
MINIO_SECRET_KEY=minioadmin
MINIO_USE_SSL=false
MINIO_UPLOAD_BUCKET=videotrimmer-uploads
MINIO_OUTPUT_BUCKET=videotrimmer-outputs

CALLBACK_SECRET=change-me-in-production

FFMPEG_PATH=ffmpeg
FFPROBE_PATH=ffprobe
TEMP_DIR=/tmp/transcoder

MAX_CONCURRENT=4
JOB_TIMEOUT_SECONDS=1800

METRICS_PORT=9090
LOG_LEVEL=info
```

---

## Go Learning Notes

| Go Concept | What It Does | PHP Analogy |
|---|---|---|
| `context.Context` | Carries deadlines and cancellation signals | Laravel request lifecycle + job timeout |
| `context.WithTimeout` | Auto-cancels after a duration | `set_time_limit()` but composable and goroutine-safe |
| `exec.CommandContext` | Runs a shell command; respects context cancellation | `shell_exec()` but killable on timeout |
| `encoding/json` | Marshal/Unmarshal structs to JSON | `json_encode()` / `json_decode()` with typed structs |
| `log/slog` | Structured key-value logging | `Log::info('msg', ['key' => 'val'])` |
| `sync.WaitGroup` | Wait for multiple goroutines to finish | Conceptually like `Promise::all()` |
| `goroutine` | Lightweight concurrent function | Like Octane's concurrent tasks, but built-in |
| `channel` | Typed queue between goroutines | Redis queue, but in-memory and type-safe |
| `signal.NotifyContext` | Catch OS signals (SIGTERM) for graceful shutdown | `pcntl_signal()` |
| `prometheus/client_golang` | Expose metrics endpoint | Laravel Pulse |
| `miniredis` (test lib) | In-memory Redis for tests | `Queue::fake()` |

**Key Go 1.25 features to use intentionally:**
- `log/slog` for all logging (not `fmt.Println`)
- `testing/synctest` for backoff tests
- GOMAXPROCS cgroup awareness (set CPU limits in Docker/K8s and observe)
- `os.StatFS()` for disk space pre-flight check

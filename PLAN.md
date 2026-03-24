# Automated Video Trimmer & Transcoder — Project Plan

> **Source of Truth** — This file governs all development decisions. Update it whenever a contract, schema, directory name, or approach changes. Never let the code drift from what's written here without updating this file first.

**Stack:** Laravel 13 + Livewire 4 + Flux UI 2 + Pest · Go 1.25 · Redis · MinIO (local) / S3 (prod) · PostgreSQL · Docker Compose → Kubernetes
**Goal:** Learn Go microservices architecture through a real, portfolio-worthy project. Server-side uploads in Phase 1; scale to K8s in Phase 2.

---

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Monorepo File Structure](#monorepo-file-structure)
3. [Database Schema](#database-schema)
4. [Service Contracts](#service-contracts)
5. [Phase 1 — Docker Compose (Local Dev)](#phase-1--docker-compose-local-dev)
6. [Phase 2 — Kubernetes (Production)](#phase-2--kubernetes-production)
7. [Edge Cases & Mitigations](#edge-cases--mitigations)
8. [Testing Strategy](#testing-strategy)
9. [Go Learning Notes](#go-learning-notes)
10. [Deployment Alternatives](#deployment-alternatives)
11. [Environment Variables Reference](#environment-variables-reference)

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│                        CLIENT / BROWSER                      │
└────────────────────────┬────────────────────────────────────┘
                         │ HTTP (multipart upload)
┌────────────────────────▼────────────────────────────────────┐
│                   LARAVEL 13 (Orchestrator)                   │
│  Auth · Upload API · Job Dispatch · Dashboard · Webhooks     │
└──────┬──────────────────────────────────────┬───────────────┘
       │ RPOPLPUSH (enqueue job)              │ POST /webhooks/transcode
       ▼                                      │ (callback when done)
┌─────────────┐                    ┌──────────▼──────────────┐
│    REDIS     │◄───────────────── │     GO 1.25 WORKER       │
│  (Queue)     │  BLPOP (pull job) │  FFmpeg · Goroutines     │
└─────────────┘                    └──────────┬──────────────┘
                                              │ Upload/Download
                              ┌───────────────▼──────────────┐
                              │      MINIO / S3               │
                              │  uploads/ + outputs/ buckets  │
                              └──────────────────────────────┘
```

**Data flow:**
1. User uploads `.mp4` → Laravel validates → saves to MinIO `uploads/` bucket
2. Laravel creates `videos` + `transcode_jobs` DB records → pushes job to Redis
3. Go worker pulls job → downloads from MinIO → runs FFmpeg → uploads result to `outputs/`
4. Go calls Laravel webhook → Laravel updates DB → notifies user

---

## Implementation Status

### Completed
- [x] Laravel 13 project foundation (`laravel-app/` directory)
- [x] Livewire 4 + Flux UI 2 + Tailwind CSS v4 configured
- [x] Laravel Fortify: login, register, password reset, email verification
- [x] Two-factor authentication (TOTP + recovery codes)
- [x] User profile management (name, email update)
- [x] Security settings page (password change, 2FA setup/disable)
- [x] Appearance settings (light/dark/system theme)
- [x] User account deletion
- [x] Full Pest test suite for all auth & settings flows
- [x] MCP integration (Laravel Boost + Herd)
- [x] Laravel Pint configured

### Remaining (Phase 1)
- [ ] Step 1.0 — Docker Compose full-stack setup
- [ ] Step 1.1 — Laravel: packages, migrations, models, upload endpoint, job dispatch
- [ ] Step 1.2 — Go worker service (`go-worker/` directory doesn't exist yet)
- [ ] Step 1.3 — Webhook handler (Laravel receives Go callbacks)
- [ ] Step 1.4 — Dashboard UI (Livewire components for upload + video list)
- [ ] Step 1.5 — Scheduled commands (ReconcileStuckJobs, PruneExpiredVideos)

### Remaining (Phase 2)
- [ ] Step 2.1 — Hardened multi-stage Dockerfiles
- [ ] Step 2.2 — Kubernetes manifests + HPA
- [ ] Step 2.3 — Prometheus metrics + structured logging
- [ ] Step 2.4 — K8s CronJobs for DB backups

---

## Local Development Setup

**Current:** Laravel Herd serves `laravel-app/` at `https://video-transcoder.test`. Uses SQLite + database queue. This remains the primary environment for Laravel-only development work.

**Docker Compose:** Required only when testing the full pipeline (Go worker + Redis + MinIO + PostgreSQL together). Step 1.0 sets this up.

**Rule:** During Steps 1.1–1.5, use Herd for rapid iteration on Laravel code. Switch to Docker Compose only when end-to-end pipeline testing is needed.

---

## Monorepo File Structure

```
video-transcoder/                         # Actual root directory name
│
├── laravel-app/                          # Laravel 13 application (already scaffolded)
│   ├── app/
│   │   ├── Http/
│   │   │   ├── Controllers/
│   │   │   │   ├── VideoController.php           # Upload, list, show, delete
│   │   │   │   ├── TranscodeJobController.php    # Job status, retry
│   │   │   │   └── WebhookController.php         # Go worker callback receiver
│   │   │   ├── Middleware/
│   │   │   │   ├── ValidateWebhookSignature.php  # HMAC verification
│   │   │   │   └── EnforceUploadQuota.php        # Check user limits before upload
│   │   │   └── Requests/
│   │   │       ├── UploadVideoRequest.php        # Validation rules
│   │   │       └── TranscodeJobRequest.php       # Job options validation
│   │   ├── Models/
│   │   │   ├── User.php
│   │   │   ├── Video.php
│   │   │   └── TranscodeJob.php
│   │   ├── Jobs/
│   │   │   └── DispatchTranscodeJob.php          # Pushes payload to Redis
│   │   ├── Services/
│   │   │   ├── VideoUploadService.php            # Handle file + DB creation
│   │   │   ├── StorageService.php                # MinIO/S3 abstraction
│   │   │   ├── TranscodeJobService.php           # Build & dispatch job payloads
│   │   │   └── WebhookService.php                # Parse & process Go callbacks
│   │   ├── Events/
│   │   │   ├── VideoUploaded.php
│   │   │   └── TranscodeCompleted.php
│   │   ├── Listeners/
│   │   │   └── NotifyUserOnComplete.php
│   │   └── Console/
│   │       └── Commands/
│   │           ├── ReconcileStuckJobs.php        # Cron: fix orphaned jobs
│   │           └── PruneExpiredVideos.php        # Cron: delete old files
│   ├── database/
│   │   ├── migrations/
│   │   │   ├── xxxx_create_videos_table.php
│   │   │   ├── xxxx_create_transcode_jobs_table.php
│   │   │   └── xxxx_add_subscription_fields_to_users_table.php
│   │   └── factories/
│   │       ├── VideoFactory.php
│   │       └── TranscodeJobFactory.php
│   ├── routes/
│   │   ├── api.php                              # REST API routes
│   │   └── web.php                              # Dashboard routes
│   ├── tests/
│   │   ├── Feature/
│   │   │   ├── VideoUploadTest.php
│   │   │   ├── WebhookTest.php
│   │   │   ├── DashboardTest.php
│   │   │   └── SubscriptionLimitsTest.php
│   │   └── Unit/
│   │       ├── VideoUploadServiceTest.php
│   │       ├── TranscodeJobServiceTest.php
│   │       ├── StorageServiceTest.php
│   │       └── WebhookServiceTest.php
│   ├── .env                                     # Laravel env (separate from Go)
│   ├── .env.testing
│   ├── CLAUDE.md                                # Laravel Boost conventions (must follow)
│   └── Dockerfile
│
├── go-worker/                            # Go 1.25 microservice
│   ├── cmd/
│   │   └── worker/
│   │       └── main.go                          # Entry point: wires everything together
│   ├── internal/
│   │   ├── config/
│   │   │   └── config.go                        # Load & validate env vars (done first)
│   │   ├── queue/
│   │   │   └── redis.go                         # BLPOP consumer + RPOPLPUSH safety
│   │   ├── storage/
│   │   │   └── minio.go                         # Download source, upload result
│   │   ├── transcoder/
│   │   │   ├── ffmpeg.go                        # os/exec FFmpeg wrapper
│   │   │   ├── options.go                       # Build FFmpeg flag sets per operation
│   │   │   └── probe.go                         # ffprobe to get video metadata
│   │   ├── callback/
│   │   │   └── client.go                        # HTTP POST to Laravel webhook
│   │   └── worker/
│   │       └── worker.go                        # Orchestrates the full job pipeline
│   ├── go.mod                                   # module videotrimmer/go-worker, go 1.25
│   ├── go.sum
│   └── Dockerfile
│
├── k8s/                                  # Kubernetes manifests (Phase 2)
│   ├── namespace.yaml
│   ├── configmap.yaml
│   ├── secrets.yaml                             # (gitignored, use sealed-secrets or vault)
│   ├── laravel-deployment.yaml
│   ├── laravel-service.yaml
│   ├── go-worker-deployment.yaml
│   ├── go-worker-hpa.yaml                       # HorizontalPodAutoscaler
│   ├── redis-deployment.yaml
│   ├── redis-service.yaml
│   ├── pvc.yaml                                 # Persistent Volume Claim for temp storage
│   ├── ingress.yaml
│   ├── jobs/
│   │   ├── cleanup-job.yaml                     # One-off: prune temp files
│   │   └── db-backup-cronjob.yaml               # Scheduled: pg_dump to S3
│   └── monitoring/
│       ├── servicemonitor.yaml                  # Prometheus scrape config
│       └── grafana-dashboard.json
│
├── docker-compose.yml                    # Full local stack
├── docker-compose.override.yml           # Dev overrides (hot reload, debug ports)
├── Makefile                              # Common commands
├── .env.example                          # Template for both services
└── PLAN.md                              # This file
```

---

## Database Schema

### `users` table (extend default Laravel migration)

```sql
ALTER TABLE users ADD COLUMN subscription_tier      ENUM('free','pro','enterprise') DEFAULT 'free';
ALTER TABLE users ADD COLUMN monthly_upload_count   INTEGER DEFAULT 0;
ALTER TABLE users ADD COLUMN monthly_upload_reset   TIMESTAMP;         -- when count resets
ALTER TABLE users ADD COLUMN storage_used_bytes     BIGINT DEFAULT 0;
ALTER TABLE users ADD COLUMN storage_limit_bytes    BIGINT DEFAULT 524288000; -- 500MB free
```

### `videos` table

```sql
CREATE TABLE videos (
    id                  BIGSERIAL PRIMARY KEY,
    user_id             BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    uuid                UUID NOT NULL UNIQUE DEFAULT gen_random_uuid(),
    original_filename   VARCHAR(255) NOT NULL,
    storage_path        VARCHAR(512) NOT NULL,   -- MinIO/S3 key: uploads/users/{id}/{uuid}.mp4
    file_size_bytes     BIGINT NOT NULL,
    duration_seconds    FLOAT,                   -- filled by Go after ffprobe
    width               INTEGER,
    height              INTEGER,
    mime_type           VARCHAR(100) NOT NULL,
    content_hash        VARCHAR(64),             -- SHA-256 of file bytes; used for deduplication
    status              ENUM('pending','queued','processing','completed','failed') DEFAULT 'pending',
    error_message       TEXT,
    created_at          TIMESTAMP DEFAULT NOW(),
    updated_at          TIMESTAMP DEFAULT NOW(),
    deleted_at          TIMESTAMP                -- soft delete
);
```

### `transcode_jobs` table

```sql
CREATE TABLE transcode_jobs (
    id                  BIGSERIAL PRIMARY KEY,
    video_id            BIGINT NOT NULL REFERENCES videos(id) ON DELETE CASCADE,
    job_uuid            UUID NOT NULL UNIQUE DEFAULT gen_random_uuid(),  -- Redis payload ID
    operation_type      ENUM('transcode','thumbnail','trim') NOT NULL,
    target_format       VARCHAR(20),             -- webm, mp4, gif, jpg
    target_resolution   VARCHAR(20),             -- e.g. "1280x720"
    trim_start_sec      FLOAT,
    trim_end_sec        FLOAT,
    thumbnail_at_sec    FLOAT,
    output_path         VARCHAR(512),            -- MinIO/S3 key for result
    status              ENUM('pending','queued','processing','completed','failed') DEFAULT 'pending',
    attempts            SMALLINT DEFAULT 0,
    max_attempts        SMALLINT DEFAULT 3,
    worker_id           VARCHAR(100),            -- hostname of Go pod that picked it up
    error_message       TEXT,
    started_at          TIMESTAMP,
    completed_at        TIMESTAMP,
    created_at          TIMESTAMP DEFAULT NOW(),
    updated_at          TIMESTAMP DEFAULT NOW()
);
```

---

## Service Contracts

### Redis Job Payload (Laravel → Go)

This is the agreed contract between services. **Treat it like an API — never change it without updating both sides.**

```json
{
  "job_uuid": "550e8400-e29b-41d4-a716-446655440000",
  "video_id": 123,
  "user_id": 456,
  "source_bucket": "videotrimmer-uploads",
  "source_key": "uploads/users/456/550e8400.mp4",
  "output_bucket": "videotrimmer-outputs",
  "output_key_prefix": "outputs/users/456/550e8400/",
  "operations": [
    {
      "type": "transcode",
      "format": "webm",
      "resolution": "1280x720",
      "crf": 28
    },
    {
      "type": "thumbnail",
      "at_second": 3.0,
      "format": "jpg"
    }
  ],
  "callback_url": "http://laravel/webhooks/transcode",
  "callback_secret": "sha256-hmac-shared-secret",
  "max_attempts": 3,
  "enqueued_at": "2026-03-21T10:00:00Z"
}
```

### Webhook Callback Payload (Go → Laravel)

```json
{
  "job_uuid": "550e8400-e29b-41d4-a716-446655440000",
  "video_id": 123,
  "status": "completed",
  "worker_id": "go-worker-pod-abc123",
  "outputs": [
    {
      "operation": "transcode",
      "format": "webm",
      "output_key": "outputs/users/456/550e8400/video.webm",
      "file_size_bytes": 2048576,
      "duration_seconds": 45.3,
      "width": 1280,
      "height": 720
    },
    {
      "operation": "thumbnail",
      "format": "jpg",
      "output_key": "outputs/users/456/550e8400/thumb.jpg",
      "file_size_bytes": 98304
    }
  ],
  "error_message": "",
  "started_at": "2026-03-21T10:00:05Z",
  "completed_at": "2026-03-21T10:01:12Z"
}
```

**HMAC Signature:** Go signs the JSON body with the shared secret using `HMAC-SHA256`. Laravel verifies using `hash_hmac('sha256', $rawBody, $secret)`. Identical pattern to FreelanceFlow's callback endpoint.

---

## Code Conventions

All Laravel code must follow the rules in `laravel-app/CLAUDE.md`. Key rules:

- Use `php artisan make:` for all new files (controllers, models, jobs, requests, etc.). Pass `--no-interaction`.
- **Form Requests** for all validation — never inline validation in controllers.
- **Service classes** for business logic — controllers stay thin (call a service, return a response).
- Constructor property promotion; no empty constructors.
- Explicit return type declarations on all methods.
- Enum keys in TitleCase (e.g., `SubscriptionTier::Free`, `VideoStatus::Processing`).
- `config('key')` everywhere — never `env()` outside of config files.
- Named routes + `route()` helper for all URL generation.
- Eloquent relationships + eager loading; avoid `DB::` raw queries.
- **Livewire SFC** pattern for all dashboard components (same pattern as `resources/views/pages/settings/`).
- **Pest** for all tests: `it()` + `expect()` syntax. Run with `php artisan test --compact`.
- Run `vendor/bin/pint --dirty --format agent` after any PHP file changes.
- Every change needs a test. Write or update tests before marking work done.

**Skills to activate during development:**
- `livewire-development` — any Livewire component work
- `fluxui-development` — any `<flux:*>` component work
- `pest-testing` — any test writing/editing
- `tailwindcss-development` — any Tailwind CSS work
- `fortify-development` — any auth feature work

---

## Phase 1 — Docker Compose (Local Dev)

### Step 1.0 — Repository & Docker Setup

**Goal:** Get all services running locally before writing any business logic.

**Tasks:**
- [ ] `git init` at repo root, create `.gitignore` (ignore `.env`, `vendor/`, `go-worker/bin/`)
- [ ] Create `docker-compose.yml` with services: `nginx`, `laravel`, `postgres`, `redis`, `minio`, `minio-setup`, `go-worker`, `mailpit`
- [ ] Create `docker-compose.override.yml` for dev-only: volume mounts for hot reload, Mailpit, debug ports
- [ ] Write `Makefile` with targets: `make up`, `make down`, `make logs`, `make test-laravel`, `make test-go`, `make migrate`, `make fresh`
- [ ] Verify all containers start and can reach each other: `docker compose up -d && docker compose ps`

**`docker-compose.yml` skeleton:**
```yaml
services:
  nginx:
    image: nginx:alpine
    ports: ["8080:80"]
    volumes:
      - ./laravel-app:/var/www/html
      - ./nginx/default.conf:/etc/nginx/conf.d/default.conf
    depends_on: [laravel]

  laravel:
    build: ./laravel-app
    volumes:
      - ./laravel-app:/var/www/html   # hot reload in dev
    environment:
      - APP_ENV=local
    depends_on: [postgres, redis, minio]

  postgres:
    image: postgres:17
    environment:
      POSTGRES_DB: videotrimmer
      POSTGRES_USER: dev
      POSTGRES_PASSWORD: secret
    volumes:
      - pgdata:/var/lib/postgresql/data
    ports: ["5432:5432"]

  redis:
    image: redis:7-alpine
    ports: ["6379:6379"]

  minio:
    image: minio/minio:latest
    command: server /data --console-address ":9001"
    environment:
      MINIO_ROOT_USER: minioadmin
      MINIO_ROOT_PASSWORD: minioadmin
    ports: ["9000:9000", "9001:9001"]
    volumes:
      - miniodata:/data

  minio-setup:
    image: minio/mc:latest
    depends_on: [minio]
    entrypoint: >
      /bin/sh -c "
      sleep 3;
      mc alias set local http://minio:9000 minioadmin minioadmin;
      mc mb local/videotrimmer-uploads --ignore-existing;
      mc mb local/videotrimmer-outputs --ignore-existing;
      mc anonymous set download local/videotrimmer-outputs;
      exit 0;
      "

  go-worker:
    build: ./go-worker
    environment:
      - REDIS_URL=redis:6379
      - MINIO_ENDPOINT=minio:9000
    depends_on: [redis, minio]

  mailpit:
    image: axllent/mailpit:latest
    ports: ["8025:8025", "1025:1025"]

volumes:
  pgdata:
  miniodata:
```

---

### Step 1.1 — Laravel Foundation

**Goal:** Auth, file upload, DB records, job dispatch.

**Tasks:**

**1.1.a — Laravel config (foundation already in place)**

Laravel 13 + Livewire 4 + Flux UI 2 + Fortify + Pest are already installed in `laravel-app/`. Remaining setup:

- [ ] `cd laravel-app && composer require league/flysystem-aws-s3-v3` (MinIO uses the S3 API)
- [ ] `composer require predis/predis` (Redis client for queue)
- [ ] Configure `config/filesystems.php` to add an `s3` disk pointing to MinIO (path-style endpoint required)
- [ ] Set `QUEUE_CONNECTION=redis` in `.env` (currently `database` for Herd dev)
- [ ] Configure `config/queue.php` with a `transcode` queue name
- [ ] Switch `DB_CONNECTION` from `sqlite` to `pgsql` in `.env` for Docker Compose (keep sqlite for Herd dev via `.env.herd` or feature flag)

**1.1.b — Migrations**
- [ ] `php artisan make:migration add_subscription_fields_to_users_table`
- [ ] `php artisan make:migration create_videos_table`
- [ ] `php artisan make:migration create_transcode_jobs_table`
- [ ] Run `php artisan migrate` — verify tables in PostgreSQL

**1.1.c — Models**
- [ ] `Video` model: `$fillable`, `$casts`, `user()` belongsTo, `transcodeJobs()` hasMany, `scopePending()`, `scopeByUser()`
- [ ] `TranscodeJob` model: `$fillable`, `$casts`, `video()` belongsTo, `isRetryable()` helper
- [ ] Add `subscription_tier`, `storageUsedBytes()` helpers to `User` model
- [ ] Factories: `VideoFactory`, `TranscodeJobFactory` (used heavily in tests)

**1.1.d — Upload endpoint**
- [ ] `UploadVideoRequest` — validation rules:
    - `file` required, mimes: `mp4,mov,avi,webm`, max size: `512000` (500MB)
    - Custom rule: `UserStorageQuotaRule` — check `storage_used_bytes < storage_limit_bytes`
    - Custom rule: `MonthlyUploadLimitRule` — check monthly count < limit
- [ ] `VideoUploadService`:
    - Generate UUID for the video
    - Build S3 key: `uploads/users/{user_id}/{uuid}.{ext}`
    - Store file to MinIO via `Storage::disk('s3')->put()`
    - Create `Video` record with `status = 'pending'`
    - Increment `users.storage_used_bytes`
    - Create `TranscodeJob` record(s) for each requested operation
    - Update `Video` status to `'queued'`
    - Dispatch `DispatchTranscodeJob` job to `transcode` queue
    - Return the created video
- [ ] `VideoController@store` — use `VideoUploadService`, return JSON response
- [ ] `VideoController@index` — paginated list, scoped to `auth()->user()`
- [ ] `VideoController@show` — single video + its jobs
- [ ] `VideoController@destroy` — soft delete, DO NOT delete from MinIO immediately (let cron handle it)

**1.1.e — Job dispatch**
- [ ] `DispatchTranscodeJob` — a standard Laravel Job that pushes a structured JSON payload to the `transcode` Redis list using `Redis::rpush()`
    - **Why not `dispatch()`?** Go reads raw JSON from Redis, not PHP-serialized payloads. Use `Redis::rpush('queue:transcode', json_encode($payload))` directly
- [ ] `TranscodeJobService::buildPayload(Video $video, array $jobs): array` — assembles the Redis payload matching the contract above

---

### Step 1.2 — Go Worker Foundation

The `go-worker/` directory does not exist yet. Start with:
```bash
mkdir go-worker && cd go-worker
go mod init videotrimmer/go-worker
# Create cmd/worker/main.go and internal/ package structure
```

Build file by file. Each file should be reviewed/tested before moving to the next.

**Build order:**

**1.2.a — `internal/config/config.go`** *(already familiar from FreelanceFlow)*
```go
type Config struct {
    RedisURL        string
    RedisQueue      string  // "queue:transcode"
    MinioEndpoint   string
    MinioBucket     string
    MinioAccessKey  string
    MinioSecretKey  string
    MinioUseSSL     bool
    CallbackSecret  string
    WorkerID        string  // os.Hostname() — identifies which pod processed the job
    FFmpegPath      string  // default: "ffmpeg"
    FFprobePath     string  // default: "ffprobe"
    TempDir         string  // working directory for FFmpeg intermediate files
    MaxConcurrent   int     // goroutines processing jobs simultaneously
    JobTimeoutSecs  int     // max seconds a single FFmpeg run may take
}

func Load() (*Config, error) // reads os.Getenv, returns error on missing required vars
```

**1.2.b — `internal/queue/redis.go`** *(similar to FreelanceFlow's queue)*

Key difference from a simple `BLPOP`: use `LMOVE` for job safety (`BRPOPLPUSH` was deprecated in Redis 6.2).
```go
// The "processing" list acts as an in-flight tracker.
// If the worker dies mid-job, a recovery goroutine can find it here.

type Consumer struct {
    client        *redis.Client
    sourceQueue   string  // "queue:transcode"
    processingKey string  // "queue:transcode:processing"
}

func (c *Consumer) Pop(ctx context.Context) ([]byte, error)
    // LMOVE sourceQueue processingKey RIGHT LEFT (with BLMOVE for blocking)
    // Atomically moves job from source → processing list
    // go-redis/v9: client.BLMove(ctx, sourceQueue, processingKey, "RIGHT", "LEFT", timeout)

func (c *Consumer) Ack(ctx context.Context, payload []byte) error
    // LREM processingKey 1 payload
    // Remove job from processing list after success

func (c *Consumer) Nack(ctx context.Context, payload []byte) error
    // LREM processingKey 1 payload
    // LPUSH sourceQueue payload  (requeue at front for retry)
    // Or move to dead-letter queue after max attempts

func (c *Consumer) RecoverStale(ctx context.Context, olderThanMinutes int) error
    // Goroutine: scan processing list, requeue jobs that have been there too long
    // LRANGE processingKey 0 -1 → parse timestamps → LMOVE back to source if stale
    // This handles the K8s pod-killed scenario
```

**Go Learning Note:** `LMOVE` (and its blocking variant `BLMOVE`) is the modern reliable queue pattern, replacing the deprecated `BRPOPLPUSH`. In PHP/Laravel you use `Queue::later()` or the job table for this — in Go you implement it explicitly. This makes the reliability contract visible and testable.

**1.2.c — `internal/storage/minio.go`** *(same role as FreelanceFlow's minio.go)*
```go
type Client struct {
    mc     *minio.Client
    cfg    *config.Config
}

func New(cfg *config.Config) (*Client, error)

func (c *Client) Download(ctx context.Context, bucket, key, destPath string) error
    // GetObject → stream to temp file on disk

func (c *Client) Upload(ctx context.Context, bucket, key, srcPath string) (int64, error)
    // FPutObject → returns file size bytes

func (c *Client) Delete(ctx context.Context, bucket, key string) error
    // RemoveObject — used for cleanup
```

**1.2.d — `internal/transcoder/probe.go`**
```go
type VideoInfo struct {
    DurationSeconds float64
    Width           int
    Height          int
    Format          string
    BitrateBps      int64
}

func Probe(ctx context.Context, ffprobePath, filePath string) (*VideoInfo, error)
    // Runs: ffprobe -v quiet -print_format json -show_streams filePath
    // Parses JSON output — this is the idiomatic Go JSON unmarshaling exercise
```

**1.2.e — `internal/transcoder/options.go`**
```go
// BuildTranscodeArgs builds the []string of FFmpeg flags for a transcode operation.
// Example output: ["-i", "input.mp4", "-c:v", "libvpx-vp9", "-crf", "28",
//                  "-b:v", "0", "-vf", "scale=1280:720", "output.webm"]
func BuildTranscodeArgs(input, output string, op payload.Operation) ([]string, error)

// BuildThumbnailArgs builds args for extracting a single frame as JPEG.
// Example: ["-i", "input.mp4", "-ss", "3", "-vframes", "1", "thumb.jpg"]
func BuildThumbnailArgs(input, output string, op payload.Operation) ([]string, error)

// BuildTrimArgs builds args for trimming without re-encoding (stream copy).
// Example: ["-i", "input.mp4", "-ss", "10", "-to", "60", "-c", "copy", "trimmed.mp4"]
func BuildTrimArgs(input, output string, op payload.Operation) ([]string, error)
```

**Go Learning Note:** This is the most testable file in the Go service. You can test all flag combinations without running FFmpeg — just check the `[]string` output. Great for unit tests.

**1.2.f — `internal/transcoder/ffmpeg.go`**
```go
type Runner struct {
    ffmpegPath string
    tempDir    string
}

// Run executes FFmpeg with the given args, streaming stderr to a logger.
// Context carries the timeout — when ctx is cancelled, FFmpeg process is killed.
func (r *Runner) Run(ctx context.Context, args []string) error
    // cmd := exec.CommandContext(ctx, r.ffmpegPath, args...)
    // cmd.Stderr = &bytes.Buffer{} → capture for error reporting
    // Returns wrapped error with stderr content on non-zero exit

// RunOperation runs a single operation end-to-end: builds args, runs FFmpeg, returns output path.
func (r *Runner) RunOperation(ctx context.Context, inputPath string, op payload.Operation, outputDir string) (string, error)
```

**Go Learning Note:** `exec.CommandContext` is the Go equivalent of PHP's `exec()` or `shell_exec()`. The key difference is the context — when the K8s pod hits CPU limits or a timeout fires, the context is cancelled and Go automatically sends `SIGKILL` to the FFmpeg child process. No zombie processes.

**1.2.g — `internal/callback/client.go`** *(same pattern as FreelanceFlow)*
```go
type Client struct {
    httpClient *http.Client
    secret     string
    workerID   string
}

func New(secret, workerID string) *Client

// Send POSTs the result payload to the callback URL with HMAC signature header.
// Retries up to 3 times with exponential backoff: 5s, 30s, 5min.
func (c *Client) Send(ctx context.Context, url string, result payload.CallbackPayload) error
    // Serialize to JSON
    // Sign: HMAC-SHA256(jsonBody, secret) → hex string
    // Set header: X-Signature: sha256=<hex>
    // POST with 10s timeout per attempt
```

**1.2.h — `internal/worker/worker.go`**

This is the orchestrator — the file that makes the whole pipeline work:

```go
type Worker struct {
    cfg         *config.Config
    queue       *queue.Consumer
    storage     *storage.Client
    transcoder  *transcoder.Runner
    callback    *callback.Client
}

func New(cfg *config.Config, ...) *Worker

// Start launches MaxConcurrent goroutines, each running processLoop.
func (w *Worker) Start(ctx context.Context)

// processLoop is what each goroutine runs: pop → process → ack/nack.
func (w *Worker) processLoop(ctx context.Context)

// process handles a single job end-to-end.
func (w *Worker) process(ctx context.Context, raw []byte) error
    // 1. Unmarshal payload
    // 2. Create job-scoped temp dir: /tmp/{job_uuid}/
    // 3. Download source file from MinIO
    // 4. Run ffprobe to get video metadata
    // 5. For each operation: run FFmpeg, upload result to MinIO
    // 6. Call Laravel callback with results
    // 7. Defer: clean up temp dir regardless of outcome
```

**1.2.i — `cmd/worker/main.go`**
```go
func main() {
    cfg, err := config.Load()
    // ... error check

    // Verify FFmpeg is available (readiness check)
    if err := checkFFmpeg(cfg.FFmpegPath); err != nil {
        log.Fatal("FFmpeg not found:", err)
    }

    worker := worker.New(cfg, ...)

    // Graceful shutdown: listen for SIGTERM/SIGINT (important for K8s pod termination)
    ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGTERM, syscall.SIGINT)
    defer stop()

    slog.Info("worker starting", "worker_id", cfg.WorkerID, "concurrency", cfg.MaxConcurrent)
    worker.Start(ctx)
    slog.Info("worker shut down cleanly")
}
```

**Go Learning Note:** `signal.NotifyContext` is the idiomatic Go 1.16+ way to handle graceful shutdown. When K8s sends `SIGTERM` before killing the pod, Go catches it, the context is cancelled, and each goroutine finishes its current job before exiting. This is what makes the worker "K8s-native."

---

### Step 1.3 — Webhook Handler (Laravel)

**Goal:** Laravel receives the Go callback, verifies it, and updates the DB.

**Tasks:**
- [ ] `ValidateWebhookSignature` middleware:
    - Read raw request body (before JSON parsing!)
    - Compute `hash_hmac('sha256', $rawBody, $secret)`
    - Compare to `X-Signature` header using `hash_equals()` (timing-safe)
    - Return 403 on mismatch
- [ ] `WebhookController@handle`:
    - Look up `TranscodeJob` by `job_uuid` — return 404 if not found
    - Idempotency check: if status is already `completed` or `failed`, return 200 (no-op)
    - Update `TranscodeJob` fields: `status`, `output_path`, `completed_at`, `worker_id`, `error_message`
    - If all jobs for a video are complete → update `Video.status = 'completed'`
    - If any job failed all retries → update `Video.status = 'failed'`
    - Fire `TranscodeCompleted` event → `NotifyUserOnComplete` listener sends email
- [ ] Register route: `POST /webhooks/transcode` — **no CSRF, yes signature middleware**
- [ ] `WebhookService::process(array $payload): void` — contains all logic (controller stays thin)

---

### Step 1.4 — Dashboard (Laravel + Livewire 4)

**Goal:** Users can upload videos, see their status, and download processed files.

Auth routes are already in place via Fortify. The dashboard at `/dashboard` currently has placeholder UI.

**Livewire components to build** (use SFC pattern, matching `resources/views/pages/settings/`):

- [ ] `VideoUpload` component (`resources/views/pages/dashboard/upload.blade.php`)
    - Livewire 4 built-in file upload with `wire:model`
    - Client-side drag-and-drop via Alpine.js
    - Progress indicator using Livewire upload events (`uploading`, `progress`, `uploaded`)
    - Triggers `VideoUploadService` on server, shows validation errors inline
    - Uses `<flux:*>` components for form elements

- [ ] `VideoList` component (`resources/views/pages/dashboard/video-list.blade.php`)
    - Paginated list of `auth()->user()->videos()->with('transcodeJobs')->latest()->paginate(10)`
    - Status badge per video: pending / queued / processing / completed / failed
    - `wire:poll.5s` to auto-refresh job statuses (replaces meta-refresh — Livewire handles this cleanly)
    - Download button for completed jobs — triggers `VideoController@download` for presigned URL
    - Storage usage bar: `storage_used_bytes / storage_limit_bytes`

- [ ] `VideoController@download` — generate presigned MinIO URL (15 min TTL) for output file
    - Authenticated: only the video owner can trigger this
    - Returns a redirect to the presigned URL (not exposing it in JSON to prevent sharing)

**Dashboard layout:** Update `resources/views/dashboard.blade.php` to include both components side-by-side or stacked.

---

### Step 1.5 — Scheduled Commands

**Goal:** Keep the system clean without manual intervention.

```php
// app/Console/Commands/ReconcileStuckJobs.php
// Runs every 15 minutes via scheduler
// Finds transcode_jobs WHERE status = 'processing' AND updated_at < NOW() - INTERVAL '30 minutes'
// If attempts < max_attempts: requeue to Redis, reset status to 'queued'
// If attempts >= max_attempts: mark as 'failed', update parent video

// app/Console/Commands/PruneExpiredVideos.php  
// Runs daily
// Finds videos soft-deleted more than 30 days ago
// Deletes from MinIO (both uploads and outputs)
// Hard-deletes DB records
// Decrements user storage_used_bytes
```

Register in `routes/console.php` (Laravel 13 style):
```php
Schedule::command('transcode:reconcile')->everyFifteenMinutes();
Schedule::command('videos:prune')->daily();
```

---

## Phase 2 — Kubernetes (Production)

**Narrative for portfolio:** *"I started with Compose, but once I modeled the CPU spikes from concurrent FFmpeg jobs, I realized I needed HPA. The migration itself taught me how K8s resource requests/limits map to Go's GOMAXPROCS cgroup awareness — in Go 1.25, you get correct goroutine scheduling for free inside K8s CPU limits."*

### Step 2.1 — Harden Dockerfiles

**Laravel `Dockerfile` (multi-stage):**
```dockerfile
FROM composer:2 AS deps
WORKDIR /app
COPY laravel-app/composer.* .
RUN composer install --no-dev --optimize-autoloader

FROM php:8.5-fpm-alpine AS runtime
RUN docker-php-ext-install pdo pdo_pgsql opcache
COPY --from=deps /app/vendor /var/www/html/vendor
COPY laravel-app/ /var/www/html/
# OPcache config for production
RUN echo "opcache.enable=1\nopcache.memory_consumption=256" >> /usr/local/etc/php/conf.d/opcache.ini
```

**Go `Dockerfile` (multi-stage → minimal image):**
```dockerfile
FROM golang:1.25-alpine AS builder
WORKDIR /app
COPY go.mod go.sum ./
RUN go mod download
COPY . .
RUN CGO_ENABLED=0 GOOS=linux go build -ldflags="-s -w" -o /worker ./cmd/worker

FROM alpine:3.21 AS runtime
# FFmpeg must be in the runtime image
RUN apk add --no-cache ffmpeg
COPY --from=builder /worker /worker
ENTRYPOINT ["/worker"]
```

**Why alpine (not scratch)?** FFmpeg has runtime dependencies (shared libs). `scratch` won't work here. Alpine is the next best thing at ~20MB.

### Step 2.2 — Kubernetes Manifests

**`go-worker-deployment.yaml`:**
```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: go-worker
  namespace: videotrimmer
spec:
  replicas: 1                          # HPA will manage this
  selector:
    matchLabels:
      app: go-worker
  template:
    metadata:
      labels:
        app: go-worker
      annotations:
        prometheus.io/scrape: "true"
        prometheus.io/port: "9090"
    spec:
      containers:
        - name: go-worker
          image: your-registry/go-worker:latest
          resources:
            requests:
              cpu: "500m"              # 0.5 CPU cores
              memory: "256Mi"
            limits:
              cpu: "2000m"             # 2 cores max per pod
              memory: "1Gi"
          env:
            - name: REDIS_URL
              valueFrom:
                secretKeyRef:
                  name: videotrimmer-secrets
                  key: redis-url
            # ... other secrets
          readinessProbe:
            exec:
              command: ["/worker", "-health-check"]  # exits 0 if ffmpeg found
            initialDelaySeconds: 5
            periodSeconds: 10
          volumeMounts:
            - name: tmp-storage
              mountPath: /tmp/transcoder
      volumes:
        - name: tmp-storage
          persistentVolumeClaim:
            claimName: transcoder-tmp-pvc
```

**`go-worker-hpa.yaml`:**
```yaml
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata:
  name: go-worker-hpa
  namespace: videotrimmer
spec:
  scaleTargetRef:
    apiVersion: apps/v1
    kind: Deployment
    name: go-worker
  minReplicas: 1
  maxReplicas: 10
  metrics:
    - type: Resource
      resource:
        name: cpu
        target:
          type: Utilization
          averageUtilization: 80
  behavior:
    scaleUp:
      stabilizationWindowSeconds: 30    # React quickly to spikes
    scaleDown:
      stabilizationWindowSeconds: 300   # Don't scale down too aggressively
```

**Go 1.25 + HPA tip:** Go 1.25 reads the pod's cgroup CPU bandwidth limit and sets `GOMAXPROCS` automatically. This means when K8s limits a pod to 2 CPUs (`limits.cpu: "2000m"`), Go uses exactly 2 OS threads — no over-subscription, no need for `uber-go/automaxprocs`.

### Step 2.3 — Observability

**In Go worker — add Prometheus metrics:**
```go
// Expose on :9090/metrics
var (
    jobsProcessed = prometheus.NewCounterVec(
        prometheus.CounterOpts{Name: "worker_jobs_total"},
        []string{"status"},             // completed, failed
    )
    jobDuration = prometheus.NewHistogram(
        prometheus.HistogramOpts{
            Name:    "worker_job_duration_seconds",
            Buckets: []float64{5, 15, 30, 60, 120, 300},
        },
    )
    activeJobs = prometheus.NewGauge(
        prometheus.GaugeOpts{Name: "worker_active_jobs"},
    )
)
```

**In Go worker — structured logging with `log/slog`:**
```go
slog.Info("job started",
    "job_uuid", job.UUID,
    "video_id", job.VideoID,
    "operations", len(job.Operations),
    "worker_id", cfg.WorkerID,
)
```

### Step 2.4 — CronJobs

**`k8s/jobs/db-backup-cronjob.yaml`:**
```yaml
apiVersion: batch/v1
kind: CronJob
metadata:
  name: db-backup
spec:
  schedule: "0 3 * * *"               # 3 AM daily
  jobTemplate:
    spec:
      template:
        spec:
          containers:
            - name: pg-dump
              image: postgres:17
              command:
                - /bin/sh
                - -c
                - pg_dump $DATABASE_URL | gzip | aws s3 cp - s3://backups/db-$(date +%Y%m%d).sql.gz
          restartPolicy: OnFailure
```

---

## Edge Cases & Mitigations

### Upload Layer (Laravel)

| Edge Case | Detection | Mitigation |
|---|---|---|
| Corrupted file (not actually a video) | PHP `finfo_file()` MIME sniff on bytes, not extension | Reject in `UploadVideoRequest` before saving |
| File exceeds PHP memory | `upload_max_filesize` / `post_max_size` in `php.ini` | Set to 512M in Docker; add explicit error handling for `UPLOAD_ERR_INI_SIZE` |
| User exceeds storage quota | Check before accepting upload | `UserStorageQuotaRule` in form request; return 422 with clear message |
| Monthly limit reached | Check count before accepting | `MonthlyUploadLimitRule` in form request |
| Duplicate file upload | SHA-256 hash comparison | Store `content_hash` on `videos` table; return existing video if hash matches |
| Upload interrupted (partial file) | PHP upload error codes | Check `$request->file('file')->isValid()` — handles all PHP upload error codes |
| Concurrent uploads hit storage exactly at limit | Race condition | DB transaction + pessimistic lock on `users.storage_used_bytes` update |

### Queue & Worker (Go)

| Edge Case | Detection | Mitigation |
|---|---|---|
| FFmpeg crashes (non-zero exit) | `cmd.Wait()` returns error | Capture stderr, log it, mark job failed, increment attempts, NACK |
| FFmpeg timeout (huge file) | `context.WithTimeout` | Per-job timeout from payload (`JobTimeoutSecs`); context cancels FFmpeg process |
| Pod killed mid-job (OOMKill/SIGKILL) | Job stays in processing list forever | `RecoverStale` goroutine requeues jobs older than 30min from `queue:transcode:processing` |
| Redis connection lost during `BLPOP` | `redis.Nil` error or connection reset | Retry loop with exponential backoff; `context.Background()` for reconnect attempts |
| MinIO unavailable for download | HTTP error / connection refused | Retry 3x with 5s delay; fail job if all retries fail |
| Disk full in temp dir | FFmpeg writes partial file, exits non-zero | Pre-flight: `os.StatFS()` check before starting; require 3x source file size free |
| Output file collision (reprocessing) | Same output key written twice | Deterministic key: `outputs/users/{uid}/{job_uuid}/{operation}.{ext}` — always idempotent |
| Go worker OOM (huge 4K video) | Pod evicted by K8s | Set `resources.limits.memory = 1Gi`; FFmpeg streams rather than loads to memory |
| FFmpeg not in container | Worker starts but can't process | Readiness probe runs `ffmpeg -version`; pod never joins service until healthy |

### Callback Layer

| Edge Case | Detection | Mitigation |
|---|---|---|
| Laravel down at callback time | HTTP 5xx / connection refused | Retry with backoff (5s, 30s, 5min); after all retries, `ReconcileStuckJobs` cron reconciles |
| Invalid HMAC signature | `hash_equals()` returns false | 403 response; log the event with source IP; never process |
| Duplicate callback (retry scenario) | `job_uuid` already `completed` in DB | Return 200 immediately (idempotent), no DB update |
| Callback for deleted video | `video_id` not found | Return 404; Go logs it but doesn't retry (no point) |
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
it('signs payload callback url with correct hmac')

// tests/Unit/WebhookServiceTest.php
it('updates job status to completed on success callback')
it('updates job status to failed on error callback')
it('marks video completed when all jobs succeed')
it('marks video failed when any job exhausts retries')
it('is idempotent for duplicate callbacks')
```

**Feature Tests** — uses DB (SQLite in-memory for speed), mocks MinIO and Redis:

```php
// tests/Feature/VideoUploadTest.php
it('authenticated user can upload a valid video')
it('unauthenticated user gets 401')
it('upload is rejected for unsupported mime type')
it('upload is rejected when file exceeds 500mb')
it('upload is rejected when storage quota is full')
it('upload is rejected when monthly limit is reached')
it('creates video record with pending status')
it('dispatches job to redis queue after upload')
it('increments user storage_used_bytes')

// tests/Feature/WebhookTest.php
it('accepts webhook with valid hmac signature')
it('returns 403 for invalid hmac signature')
it('returns 200 and no-ops for duplicate job_uuid')
it('returns 404 for unknown job_uuid')
it('fires TranscodeCompleted event on success')
it('sends email notification to user on completion')

// tests/Feature/SubscriptionLimitsTest.php
it('free user is limited to 500mb storage')
it('pro user has 50gb storage limit')
it('monthly upload count resets on first of month')
```

**Key Pest patterns to use:**
```php
// Fake storage so tests don't hit real MinIO
Storage::fake('s3');

// Fake queue/Redis so tests don't require Redis
Queue::fake();
Bus::fake();
Event::fake();
Mail::fake();

// Use factories for readable setup
$user = User::factory()->create(['subscription_tier' => 'free', 'storage_used_bytes' => 500 * 1024 * 1024 - 1]);
$file = UploadedFile::fake()->create('video.mp4', 1024, 'video/mp4');
```

### Go (standard `testing` + `testify`)

**Unit Tests — no real external services:**

```go
// internal/transcoder/options_test.go
func TestBuildTranscodeArgs_WebM(t *testing.T)     // verify libvpx-vp9 flags
func TestBuildTranscodeArgs_Resolution(t *testing.T) // verify scale filter
func TestBuildThumbnailArgs_AtSecond(t *testing.T)  // verify -ss flag
func TestBuildTrimArgs_StreamCopy(t *testing.T)     // verify -c copy (no re-encode)
func TestBuildTranscodeArgs_UnknownFormat(t *testing.T) // expect error

// internal/callback/client_test.go  (mock HTTP server)
func TestSend_Success(t *testing.T)
func TestSend_RetriesOn5xx(t *testing.T)
func TestSend_HMACSignatureCorrect(t *testing.T)
func TestSend_GivesUpAfterMaxRetries(t *testing.T)

// internal/config/config_test.go
func TestLoad_MissingRequiredVar(t *testing.T)    // expect error
func TestLoad_AllVarsPresent(t *testing.T)
```

**Integration Tests — use `miniredis` (in-memory Redis):**
```go
// internal/queue/redis_test.go
func TestConsumer_Pop_MovesToProcessingList(t *testing.T)
func TestConsumer_Ack_RemovesFromProcessingList(t *testing.T)
func TestConsumer_Nack_RequeuesJob(t *testing.T)
func TestConsumer_RecoverStale_RequeuesOldJobs(t *testing.T)
```

**Go Testing Tip — `testing/synctest` (Go 1.25):**  
Use `synctest.Run` to test the retry backoff logic in `callback/client.go` with virtualized time. Without it, a test of "retry after 30 seconds" would actually wait 30 seconds. With `synctest`, the fake clock advances instantly.

```go
func TestSend_RetriesOn5xx(t *testing.T) {
    synctest.Run(func() {
        // Set up mock HTTP server that returns 500 twice then 200
        // Call client.Send()
        // synctest advances fake time through the backoff delays
        // Assert: 3 total requests made, final result is nil error
    })
}
```

---

## Go Learning Notes

Concepts you'll encounter in this project, with PHP analogies:

| Go Concept | What It Does | PHP Analogy |
|---|---|---|
| `context.Context` | Carries deadlines and cancellation signals across function calls | Laravel's request lifecycle + job timeout |
| `context.WithTimeout` | Creates a context that auto-cancels after a duration | `set_time_limit()` but composable and goroutine-safe |
| `exec.CommandContext` | Runs a shell command; respects context cancellation | `shell_exec()` but killable on timeout |
| `os/exec` stderr capture | Read process output | `exec()` with `$output` param |
| `encoding/json` | Marshal/Unmarshal structs to JSON | `json_encode()` / `json_decode()` with typed structs |
| `log/slog` | Structured key-value logging | Laravel's `Log::info('msg', ['key' => 'val'])` |
| `sync.WaitGroup` | Wait for multiple goroutines to finish | `array_map` + `Promise::all()` conceptually |
| `goroutine` | Lightweight concurrent function | Similar to Octane's concurrent tasks, but built-in |
| `channel` | Typed queue between goroutines | Redis queue, but in-memory and type-safe |
| `signal.NotifyContext` | Catch OS signals (SIGTERM) for graceful shutdown | Laravel's `pcntl_signal()` |
| `prometheus/client_golang` | Expose metrics endpoint | Laravel Pulse |
| `miniredis` (test lib) | In-memory Redis for tests | `Queue::fake()` |

**Key Go 1.25 features to use intentionally:**
- `log/slog` for all logging (not `fmt.Println`)
- `testing/synctest` for backoff tests
- GOMAXPROCS cgroup awareness (set CPU limits in Docker/K8s and observe)
- `os.StatFS()` for disk space pre-flight check

---

## Deployment Alternatives

| Option | Provider | Cost | K8s | Best For |
|---|---|---|---|---|
| **Managed K8s (recommended)** | DigitalOcean DOKS + Spaces | ~$30/mo | ✅ Managed | Portfolio demo with real K8s + S3-compatible storage |
| Managed K8s | GKE Autopilot | ~$50/mo | ✅ Managed | Enterprise skill signal; best autoscaling |
| Managed K8s | EKS (AWS) | ~$70/mo | ✅ Managed | Most common in job listings |
| Self-managed K8s | Hetzner VPS + k3s | ~$15/mo | ✅ Self | Cheapest; teaches more ops but more work |
| PaaS (skip K8s) | Fly.io | ~$20/mo | ❌ | Fast deploy; `fly scale count` replaces HPA |
| PaaS (skip K8s) | Railway | ~$15/mo | ❌ | Easiest DX; background workers built-in |
| Serverless workers | Google Cloud Run | Pay-per-use | ❌ | Go workers scale to zero; good for low traffic |

**Recommendation:** DigitalOcean DOKS + DigitalOcean Spaces. Cheapest managed K8s, Spaces is S3-compatible so no code changes from MinIO, and the portfolio story is clean: *"Deployed to managed K8s on DigitalOcean with HPA scaling Go worker pods based on CPU."*

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
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_QUEUE=transcode             # Laravel queue name

FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=minioadmin
AWS_SECRET_ACCESS_KEY=minioadmin
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=videotrimmer-uploads
AWS_ENDPOINT=http://minio:9000
AWS_USE_PATH_STYLE_ENDPOINT=true  # Required for MinIO

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

MAX_CONCURRENT=4                  # goroutines processing jobs simultaneously
JOB_TIMEOUT_SECONDS=1800          # 30 minutes max per job

METRICS_PORT=9090                 # Prometheus scrape endpoint
LOG_LEVEL=info                    # debug | info | warn | error
```

---

## Makefile Reference

```makefile
.PHONY: up down logs test-laravel test-go migrate fresh shell-laravel shell-go

up:
	docker compose up -d

down:
	docker compose down

logs:
	docker compose logs -f

migrate:
	docker compose exec laravel php artisan migrate

fresh:
	docker compose exec laravel php artisan migrate:fresh --seed

test-laravel:
	docker compose exec laravel php artisan test --compact --parallel

test-go:
	docker compose exec go-worker go test ./...

test-go-verbose:
	docker compose exec go-worker go test -v -race ./...

shell-laravel:
	docker compose exec laravel sh

shell-go:
	docker compose exec go-worker sh

build:
	docker compose build --no-cache

minio-ui:
	@echo "MinIO console: http://localhost:9001 (minioadmin / minioadmin)"

mail:
	@echo "Mailpit UI: http://localhost:8025"
```

---

*Start with `make up` and verify all services are healthy before writing any application code.*  
*Build order: DB migrations → Models → Upload endpoint → Redis dispatch → Go config → Go queue → Go storage → Go transcoder → Go worker → Webhook handler → Dashboard → Tests.*

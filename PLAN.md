# Video Transcoder — Project Plan

> **Source of Truth** — This file governs all development decisions. Update it whenever a contract, schema, or approach changes. Never let the code drift from what's written here without updating this file first.

**Stack:** Laravel 13 + Livewire 4 + Flux UI 2 + Pest · Go 1.25 · Redis · MinIO (local) / S3 (prod) · PostgreSQL · Docker Compose → Kubernetes
**Goal:** Learn Go microservices architecture through a real, portfolio-worthy project.

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
       │ RPUSH (enqueue job)                  │ POST /webhooks/transcode
       ▼                                      │ (callback when done)
┌─────────────┐                    ┌──────────▼──────────────┐
│    REDIS     │◄───────────────── │     GO 1.25 WORKER       │
│  (Queue)     │  BLMOVE (pull)    │  FFmpeg · Goroutines     │
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
3. Go worker pulls job via `BLMOVE` → downloads from MinIO → runs FFmpeg → uploads result to `outputs/`
4. Go calls Laravel webhook → Laravel updates DB → notifies user

---

## Implementation Status

### Completed
- [x] Laravel 13 + Livewire 4 + Flux UI 2 + Tailwind CSS v4 foundation
- [x] Laravel Fortify: login, register, password reset, email verification
- [x] Two-factor authentication (TOTP + recovery codes)
- [x] User profile, security settings, appearance settings, account deletion
- [x] Full Pest test suite for all auth & settings flows
- [x] MCP integration (Laravel Boost + Herd) + Laravel Pint configured
- [x] **Step 1.1** — Packages, migrations, models, upload API, job dispatch (61 tests)
- [x] **Step 1.3** — Webhook handler: HMAC verification, idempotency, video status rollup (12 tests)

### Remaining (Phase 1)
- [ ] **Step 1.0** — Docker Compose full-stack setup → [docs/step-1.0-docker-setup.md](docs/step-1.0-docker-setup.md)
- [ ] **Step 1.2** — Go worker service → [docs/step-1.2-go-worker.md](docs/step-1.2-go-worker.md)
- [ ] **Step 1.4** — Dashboard UI (Livewire upload + video list) → [docs/step-1.4-dashboard.md](docs/step-1.4-dashboard.md)
- [ ] **Step 1.5** — Scheduled commands (ReconcileStuckJobs, PruneExpiredVideos) → [docs/step-1.5-scheduled-commands.md](docs/step-1.5-scheduled-commands.md)

### Remaining (Phase 2)
- [ ] **Step 2.x** — Kubernetes, Dockerfiles, observability → [docs/phase-2-kubernetes.md](docs/phase-2-kubernetes.md)

---

## Local Development Setup

**Current:** Laravel Herd serves `laravel-app/` at `https://video-transcoder.test`. Uses SQLite + database queue.

**Docker Compose:** Required only when testing the full pipeline (Go + Redis + MinIO + PostgreSQL together). See [docs/step-1.0-docker-setup.md](docs/step-1.0-docker-setup.md).

**Rule:** During Steps 1.4–1.5, use Herd for rapid iteration. Switch to Docker Compose only for end-to-end pipeline testing.

---

## Database Schema

### `users` table additions

```sql
ALTER TABLE users ADD COLUMN subscription_tier      ENUM('free','pro','enterprise') DEFAULT 'free';
ALTER TABLE users ADD COLUMN monthly_upload_count   INTEGER DEFAULT 0;
ALTER TABLE users ADD COLUMN monthly_upload_reset   TIMESTAMP;
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
    storage_path        VARCHAR(512) NOT NULL,   -- MinIO key: uploads/users/{id}/{uuid}.mp4
    file_size_bytes     BIGINT NOT NULL,
    duration_seconds    FLOAT,
    width               INTEGER,
    height              INTEGER,
    mime_type           VARCHAR(100) NOT NULL,
    content_hash        VARCHAR(64),             -- SHA-256; used for deduplication
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
    job_uuid            UUID NOT NULL UNIQUE DEFAULT gen_random_uuid(),
    operation_type      ENUM('transcode','thumbnail','trim') NOT NULL,
    target_format       VARCHAR(20),
    target_resolution   VARCHAR(20),
    trim_start_sec      FLOAT,
    trim_end_sec        FLOAT,
    thumbnail_at_sec    FLOAT,
    output_path         VARCHAR(512),
    status              ENUM('pending','queued','processing','completed','failed') DEFAULT 'pending',
    attempts            SMALLINT DEFAULT 0,
    max_attempts        SMALLINT DEFAULT 3,
    worker_id           VARCHAR(100),
    error_message       TEXT,
    started_at          TIMESTAMP,
    completed_at        TIMESTAMP,
    created_at          TIMESTAMP DEFAULT NOW(),
    updated_at          TIMESTAMP DEFAULT NOW()
);
```

---

## Service Contracts

**Treat these like an API — never change without updating both sides (Laravel + Go).**

### Redis Job Payload (Laravel → Go)

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
    { "type": "transcode", "format": "webm", "resolution": "1280x720", "crf": 28 },
    { "type": "thumbnail", "at_second": 3.0, "format": "jpg" }
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
      "output_key": "outputs/users/456/550e8400/video.webm",
      "file_size_bytes": 2048576,
      "duration_seconds": 45.3,
      "width": 1280,
      "height": 720
    }
  ],
  "error_message": "",
  "started_at": "2026-03-21T10:00:05Z",
  "completed_at": "2026-03-21T10:01:12Z"
}
```

**HMAC Signature:** Go signs with `HMAC-SHA256(jsonBody, secret)`. Laravel verifies with `hash_equals()`. Header: `X-Signature: sha256=<hex>`.

---

## Code Conventions

All Laravel code must follow `laravel-app/CLAUDE.md`. Key rules:

- `php artisan make:` for all new files. Pass `--no-interaction`.
- **Form Requests** for all validation — never inline.
- **Service classes** for business logic — controllers stay thin.
- Constructor property promotion; no empty constructors.
- Explicit return types on all methods.
- `config('key')` everywhere — never `env()` outside config files.
- Named routes + `route()` for all URL generation.
- Eloquent relationships + eager loading; avoid `DB::` raw queries.
- **Livewire SFC** pattern for all dashboard components.
- **Pest** for all tests: `it()` + `expect()`. Run with `php artisan test --compact`.
- Run `vendor/bin/pint --dirty --format agent` after any PHP file changes.
- Every change needs a test.

**Skills to activate:**
- `livewire-development` — any Livewire component work
- `fluxui-development` — any `<flux:*>` component work
- `pest-testing` — any test writing/editing
- `tailwindcss-development` — any Tailwind CSS work

---

## Step File Index

| File | Contents |
|---|---|
| [docs/step-1.0-docker-setup.md](docs/step-1.0-docker-setup.md) | Docker Compose, Makefile |
| [docs/step-1.1-laravel-foundation.md](docs/step-1.1-laravel-foundation.md) | Laravel setup, migrations, models, upload API (COMPLETE) |
| [docs/step-1.2-go-worker.md](docs/step-1.2-go-worker.md) | Go worker build order, all internal packages |
| [docs/step-1.3-webhook-handler.md](docs/step-1.3-webhook-handler.md) | Webhook middleware, service, events (COMPLETE) |
| [docs/step-1.4-dashboard.md](docs/step-1.4-dashboard.md) | Livewire components: VideoUpload, VideoList |
| [docs/step-1.5-scheduled-commands.md](docs/step-1.5-scheduled-commands.md) | ReconcileStuckJobs, PruneExpiredVideos |
| [docs/phase-2-kubernetes.md](docs/phase-2-kubernetes.md) | Dockerfiles, K8s manifests, HPA, observability |
| [docs/reference.md](docs/reference.md) | Edge cases, testing strategy, env vars, Go learning notes |

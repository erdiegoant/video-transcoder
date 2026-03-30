# Video Transcoder

A full-stack automated video trimmer and transcoder built to learn Go microservices architecture. Upload a video, configure trim/transcode/thumbnail operations, and a Go worker processes it asynchronously using FFmpeg — all orchestrated through a Laravel dashboard.

## Stack

| Layer | Technology |
|---|---|
| Web app | Laravel 13 + Livewire 4 + Flux UI + Tailwind CSS v4 |
| Auth | Laravel Fortify (login, 2FA, email verification) |
| Worker | Go 1.25 + FFmpeg |
| Queue | Redis |
| Storage | MinIO (local) / AWS S3 (production) |
| Database | PostgreSQL (Docker) / SQLite (local dev) |
| Testing | Pest PHP v4 + Go `testing` |
| Deployment | Docker Compose → Kubernetes |

## Architecture

```
Browser ←─ WebSocket (Reverb) ─── Laravel (upload + dashboard) → Redis queue → Go Worker → MinIO/S3
                                           ↑                                         |
                                           └──────── webhook callback (HMAC) ────────┘
```

1. User uploads a video — Laravel validates, stores in MinIO, creates DB records, pushes a JSON job to Redis
2. Go worker pulls the job, sends a `processing` callback to Laravel, then runs FFmpeg (transcode / trim / thumbnail)
3. Worker uploads the result to MinIO and POSTs a signed `completed`/`failed` webhook back to Laravel
4. Laravel updates job status and pushes real-time status updates to the browser via Reverb WebSockets

## Features

- Video upload with quota enforcement (per-user storage + monthly limits)
- Three operation types: **transcode** (format/resolution), **trim** (start/end seconds), **thumbnail** (frame extraction)
- Self-service subscription tiers (Free 500 MB / Pro 5 GB / Enterprise 50 GB) — plan selector in the dashboard sidebar
- Real-time job status updates via Laravel Reverb WebSockets (no polling)
- Presigned download URLs for processed files
- HMAC-signed webhook callbacks between Go and Laravel
- Concurrent job processing with configurable goroutine pool
- Graceful shutdown (SIGTERM-aware for Kubernetes pod termination)
- Prometheus metrics endpoint on the Go worker
- Scheduled commands: stuck job recovery, expired file pruning

## Local Development

The Laravel app runs on [Laravel Herd](https://herd.laravel.com) at `https://video-transcoder.test`.

```bash
# Laravel app
cd laravel-app
composer install
npm install && npm run dev
php artisan migrate
```

For the full pipeline (Go worker + Redis + MinIO + PostgreSQL), use Docker Compose.

> Docker Compose serves the app on **port 8080** to avoid conflicting with Herd (port 80).

```bash
make up       # start all services (first run builds images)
make migrate  # run migrations against PostgreSQL
make logs     # tail logs from all services
make down     # stop all services
```

**First time only** — if `laravel-app/vendor/` doesn't exist on the host:

```bash
make install  # runs composer install inside the app container
```

**Service URLs:**

| Service | URL |
|---|---|
| Laravel app | http://localhost:8080 |
| Reverb WebSocket | ws://localhost:6001 |
| MinIO console | http://localhost:9001 (minioadmin / minioadmin) |
| Mailpit | http://localhost:8025 |

**Other useful commands:**

```bash
make fresh    # wipe and re-seed the database
make test     # run the Pest test suite inside the container
make shell    # open a shell in the app container
make build    # rebuild images without cache
```

The `docker-compose.override.yml` mounts `laravel-app/` into the running containers, so PHP changes are reflected immediately without rebuilding.

## Project Status

- [x] Laravel foundation — auth, 2FA, settings, Livewire UI
- [x] Video models, migrations, upload API
- [x] MinIO/S3 storage integration
- [x] Webhook handler (HMAC-verified, idempotent)
- [x] Livewire dashboard (upload + video list with real-time WebSocket updates via Reverb)
- [x] Trim operation UI in upload component (start/end time inputs)
- [x] Self-service subscription tier selector (Free / Pro / Enterprise)
- [x] Scheduled commands (stuck job recovery, expired file pruning)
- [x] Docker Compose full-stack setup
- [x] Go worker service (FFmpeg, Redis queue, MinIO, HMAC callback)
- [ ] Kubernetes deployment (Phase 2)

## License

MIT

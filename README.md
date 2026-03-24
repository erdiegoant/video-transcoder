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
Browser → Laravel (upload + dashboard) → Redis queue → Go Worker → MinIO/S3
                ↑                                            |
                └──────── webhook callback (HMAC) ──────────┘
```

1. User uploads a video — Laravel validates, stores in MinIO, creates DB records, pushes a JSON job to Redis
2. Go worker pulls the job, downloads the file, runs FFmpeg (transcode / trim / thumbnail)
3. Worker uploads the result to MinIO and POSTs a signed webhook back to Laravel
4. Laravel updates job status and notifies the user

## Features

- Video upload with quota enforcement (per-user storage + monthly limits)
- Three operation types: **transcode** (format/resolution), **trim** (start/end), **thumbnail** (frame extraction)
- Real-time job status dashboard with Livewire polling
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

For the full pipeline (Go worker + Redis + MinIO + PostgreSQL), use Docker Compose (see Phase 1 in `PLAN.md`):

```bash
make up       # start all services
make migrate  # run migrations
make logs     # tail logs
make down     # stop all services
```

## Project Status

- [x] Laravel foundation — auth, 2FA, settings, Livewire UI
- [ ] Video models, migrations, upload API
- [ ] MinIO/S3 storage integration
- [ ] Go worker service
- [ ] Webhook handler
- [ ] Livewire dashboard (upload + video list)
- [ ] Docker Compose full-stack setup
- [ ] Kubernetes deployment (Phase 2)

## License

MIT

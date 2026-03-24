# Step 1.3 — Webhook Handler (Laravel)

**Goal:** Laravel receives the Go callback, verifies it, and updates the DB.

## Status: COMPLETE

All tasks implemented and tested (12 tests passing).

---

## Implemented Files

| File | Description |
|---|---|
| `app/Http/Middleware/ValidateWebhookSignature.php` | HMAC-SHA256 verification, 403 on mismatch |
| `app/Events/TranscodeCompleted.php` | Event with `Video $video` and `TranscodeJob $job` |
| `app/Listeners/NotifyUserOnComplete.php` | ShouldQueue, logs for now (email TODO) |
| `app/Services/WebhookService.php` | process() with idempotency + video status rollup |
| `app/Http/Controllers/WebhookController.php` | Thin controller, delegates to service |
| `routes/web.php` | `POST webhooks/transcode` with signature middleware |
| `bootstrap/app.php` | CSRF exclusion for `webhooks/*` |
| `app/Providers/AppServiceProvider.php` | Event listener registered |
| `tests/Feature/WebhookTest.php` | 12 tests |

---

## Key Design Decisions

**Signature verification:** Raw request body (`$request->getContent()`) is signed before JSON parsing. `hash_equals()` used for timing-safe comparison. Header: `X-Signature: sha256=<hex>`.

**Idempotency:** If a job is already `completed` or `failed`, the handler returns 200 and no-ops. Handles duplicate Go retries safely.

**Video status rollup:** After updating the job, `WebhookService` checks all jobs for the video:
- All `completed` → video status = `completed`
- Any `failed` + none still processing → video status = `failed`
- Any still `processing` or `queued` → video status unchanged

**Event:** `TranscodeCompleted` is fired only on success. `NotifyUserOnComplete` listener is queued (email stub for later).

---

## Webhook Payload Contract (Go → Laravel)

```json
{
  "job_uuid": "550e8400-e29b-41d4-a716-446655440000",
  "video_id": 123,
  "status": "completed",
  "worker_id": "go-worker-pod-abc123",
  "outputs": [
    {
      "operation": "transcode",
      "output_key": "outputs/users/456/550e8400/video.mp4",
      "file_size_bytes": 2048576
    }
  ],
  "error_message": "",
  "started_at": "2026-03-21T10:00:05Z",
  "completed_at": "2026-03-21T10:01:12Z"
}
```

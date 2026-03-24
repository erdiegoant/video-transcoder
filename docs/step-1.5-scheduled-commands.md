# Step 1.5 — Scheduled Commands

**Goal:** Keep the system clean without manual intervention.

## Status: NOT STARTED

---

## Commands to Build

### `ReconcileStuckJobs` — runs every 15 minutes

```
app/Console/Commands/ReconcileStuckJobs.php
```

Logic:
- Find `transcode_jobs` where `status = 'processing'` AND `updated_at < NOW() - 30 minutes`
- If `attempts < max_attempts`: requeue to Redis (set status back to `'queued'`, increment `attempts`)
- If `attempts >= max_attempts`: mark as `'failed'`, update parent video status

### `PruneExpiredVideos` — runs daily

```
app/Console/Commands/PruneExpiredVideos.php
```

Logic:
- Find `videos` soft-deleted more than 30 days ago
- Delete from MinIO: both `uploads/` and `outputs/` objects for the video
- Hard-delete DB records (force delete)
- Decrement `users.storage_used_bytes`

---

## Scheduler Registration

In `routes/console.php` (Laravel 13 style):

```php
Schedule::command('transcode:reconcile')->everyFifteenMinutes();
Schedule::command('videos:prune')->daily();
```

---

## Tasks

- [ ] `php artisan make:command --no-interaction ReconcileStuckJobs`
- [ ] `php artisan make:command --no-interaction PruneExpiredVideos`
- [ ] Register both in `routes/console.php`
- [ ] Write tests for both commands

---

## Tests

```php
// tests/Feature/ReconcileStuckJobsTest.php
it('requeues stuck processing job within attempt limit')
it('marks job failed when max attempts exceeded')
it('marks parent video failed when all jobs exceed max attempts')
it('does not touch jobs updated recently')

// tests/Feature/PruneExpiredVideosTest.php
it('hard deletes videos soft deleted over 30 days ago')
it('does not delete recently soft-deleted videos')
it('decrements user storage_used_bytes')
```

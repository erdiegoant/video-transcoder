# Step 1.4 — Dashboard (Laravel + Livewire 4)

**Goal:** Users can upload videos, see their status, and download processed files.

## Status: NOT STARTED

Auth routes are already in place via Fortify. The dashboard at `/dashboard` currently has placeholder UI.

**Skills to activate:** `livewire-development`, `fluxui-development`, `tailwindcss-development`, `pest-testing`

---

## Livewire Components to Build

Use SFC (Single-File Component) pattern — same as `resources/views/pages/settings/`.

### `VideoUpload` component
File: `resources/views/pages/dashboard/upload.blade.php`

- Livewire 4 built-in file upload with `wire:model`
- Client-side drag-and-drop via Alpine.js
- Progress indicator using Livewire upload events (`uploading`, `progress`, `uploaded`)
- On server: calls `VideoUploadService`, shows validation errors inline
- Uses `<flux:*>` components for form elements

### `VideoList` component
File: `resources/views/pages/dashboard/video-list.blade.php`

- Paginated list: `auth()->user()->videos()->with('transcodeJobs')->latest()->paginate(10)`
- Status badge per video: pending / queued / processing / completed / failed
- `wire:poll.5s` to auto-refresh job statuses (no meta-refresh needed — Livewire handles this)
- Download button for completed jobs — calls `VideoController@download` for presigned URL redirect
- Storage usage bar: `storage_used_bytes / storage_limit_bytes`

---

## Additional Tasks

- [ ] `VideoController@download` — generate presigned MinIO URL (15 min TTL), redirect to it
  - Only the video owner can trigger this (policy check)
  - Redirect response (not exposing URL in JSON to prevent link sharing)
- [ ] Update `resources/views/dashboard.blade.php` to include both components (side-by-side or stacked)

---

## Tests

```php
// tests/Feature/DashboardTest.php
it('authenticated user sees dashboard')
it('unauthenticated user is redirected to login')
it('video list shows user videos only')
it('video list polls for status updates')
it('download redirects to presigned url for completed job')
it('download is forbidden for another user video')
```

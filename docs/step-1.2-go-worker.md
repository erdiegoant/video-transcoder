# Step 1.2 — Go Worker

**Goal:** Go microservice that consumes Redis jobs, runs FFmpeg, uploads results, calls back to Laravel.

## Status: COMPLETE

All packages built and tested. See `go-worker/` directory.

---

## Build Order

### 1.2.a — `internal/config/config.go`

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

---

### 1.2.b — `internal/queue/redis.go`

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

**Go Learning Note:** `LMOVE` (and its blocking variant `BLMOVE`) is the modern reliable queue pattern, replacing the deprecated `BRPOPLPUSH`. In PHP/Laravel you use `Queue::later()` or the job table — in Go you implement it explicitly.

---

### 1.2.c — `internal/storage/minio.go`

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

---

### 1.2.d — `internal/transcoder/probe.go`

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
    // Parses JSON output
```

---

### 1.2.e — `internal/transcoder/options.go`

Most testable file — unit tests just check the `[]string` output, no FFmpeg needed.

```go
// BuildTranscodeArgs builds FFmpeg flags for a transcode operation.
// Example: ["-i", "input.mp4", "-c:v", "libvpx-vp9", "-crf", "28",
//           "-b:v", "0", "-vf", "scale=1280:720", "output.webm"]
func BuildTranscodeArgs(input, output string, op payload.Operation) ([]string, error)

// BuildThumbnailArgs builds args for extracting a single frame as JPEG.
// Example: ["-i", "input.mp4", "-ss", "3", "-vframes", "1", "thumb.jpg"]
func BuildThumbnailArgs(input, output string, op payload.Operation) ([]string, error)

// BuildTrimArgs builds args for trimming without re-encoding (stream copy).
// Example: ["-i", "input.mp4", "-ss", "10", "-to", "60", "-c", "copy", "trimmed.mp4"]
func BuildTrimArgs(input, output string, op payload.Operation) ([]string, error)
```

---

### 1.2.f — `internal/transcoder/ffmpeg.go`

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

**Go Learning Note:** `exec.CommandContext` is the Go equivalent of PHP's `exec()`. The key difference: when the K8s pod hits CPU limits or a timeout fires, the context is cancelled and Go automatically sends `SIGKILL` to the FFmpeg child process. No zombie processes.

---

### 1.2.g — `internal/callback/client.go`

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

---

### 1.2.h — `internal/worker/worker.go`

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

---

### 1.2.i — `cmd/worker/main.go`

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

**Go Learning Note:** `signal.NotifyContext` is the idiomatic Go 1.16+ way to handle graceful shutdown. When K8s sends `SIGTERM`, Go catches it, the context is cancelled, and each goroutine finishes its current job before exiting.

---

## Go Tests

```go
// internal/transcoder/options_test.go
func TestBuildTranscodeArgs_WebM(t *testing.T)
func TestBuildTranscodeArgs_Resolution(t *testing.T)
func TestBuildThumbnailArgs_AtSecond(t *testing.T)
func TestBuildTrimArgs_StreamCopy(t *testing.T)
func TestBuildTranscodeArgs_UnknownFormat(t *testing.T) // expect error

// internal/callback/client_test.go (mock HTTP server)
func TestSend_Success(t *testing.T)
func TestSend_RetriesOn5xx(t *testing.T)
func TestSend_HMACSignatureCorrect(t *testing.T)
func TestSend_GivesUpAfterMaxRetries(t *testing.T)

// internal/config/config_test.go
func TestLoad_MissingRequiredVar(t *testing.T)
func TestLoad_AllVarsPresent(t *testing.T)

// internal/queue/redis_test.go (uses miniredis)
func TestConsumer_Pop_MovesToProcessingList(t *testing.T)
func TestConsumer_Ack_RemovesFromProcessingList(t *testing.T)
func TestConsumer_Nack_RequeuesJob(t *testing.T)
func TestConsumer_RecoverStale_RequeuesOldJobs(t *testing.T)
```

**Go 1.25 tip:** Use `testing/synctest` to test retry backoff in `callback/client.go` with virtualized time — avoids actually waiting 30 seconds in tests.

package worker

import (
	"context"
	"encoding/json"
	"fmt"
	"log/slog"
	"os"
	"path/filepath"
	"sync"
	"time"

	"videotrimmer/go-worker/internal/callback"
	"videotrimmer/go-worker/internal/config"
	"videotrimmer/go-worker/internal/payload"
	"videotrimmer/go-worker/internal/queue"
	"videotrimmer/go-worker/internal/storage"
	"videotrimmer/go-worker/internal/transcoder"
)

// Worker orchestrates the full job lifecycle: pop → download → transcode → upload → callback.
type Worker struct {
	cfg        *config.Config
	queue      *queue.Consumer
	storage    *storage.Client
	transcoder *transcoder.Runner
	callback   *callback.Client
}

// New wires up all dependencies into a Worker.
func New(
	cfg *config.Config,
	q *queue.Consumer,
	s *storage.Client,
	t *transcoder.Runner,
	c *callback.Client,
) *Worker {
	return &Worker{
		cfg:        cfg,
		queue:      q,
		storage:    s,
		transcoder: t,
		callback:   c,
	}
}

// Start launches cfg.MaxConcurrent goroutines and blocks until ctx is cancelled
// and all in-flight jobs finish. This is the main entry point called from main.go.
func (w *Worker) Start(ctx context.Context) {
	var wg sync.WaitGroup

	for range w.cfg.MaxConcurrent {
		wg.Add(1)
		go func() {
			defer wg.Done()
			w.processLoop(ctx)
		}()
	}

	wg.Wait()
}

// processLoop runs on each goroutine: it pops a job, processes it, then acks or
// nacks depending on outcome. Exits cleanly when ctx is cancelled.
func (w *Worker) processLoop(ctx context.Context) {
	for {
		raw, err := w.queue.Pop(ctx)
		if err != nil {
			if ctx.Err() != nil {
				return // context cancelled — clean shutdown
			}
			slog.Error("queue pop failed", "error", err)
			continue
		}

		if err := w.process(ctx, raw); err != nil {
			slog.Error("job failed", "error", err)

			if nackErr := w.queue.Nack(ctx, raw); nackErr != nil {
				slog.Error("nack failed", "error", nackErr)
			}

			continue
		}

		if err := w.queue.Ack(ctx, raw); err != nil {
			slog.Error("ack failed", "error", err)
		}
	}
}

// process handles one job end-to-end. It always attempts to send a callback
// to Laravel so the job status is updated regardless of success or failure.
func (w *Worker) process(ctx context.Context, raw []byte) error {
	startedAt := time.Now()

	var job payload.JobPayload
	if err := json.Unmarshal(raw, &job); err != nil {
		return fmt.Errorf("unmarshal job: %w", err)
	}

	slog.Info("job started", "job_uuid", job.JobUUID, "operations", len(job.Operations))

	// Apply a per-job timeout so a runaway FFmpeg process can't block the goroutine forever.
	jobCtx, cancel := context.WithTimeout(ctx, time.Duration(w.cfg.JobTimeoutSecs)*time.Second)
	defer cancel()

	// Create an isolated temp directory for this job's intermediate files.
	tmpDir := filepath.Join(w.cfg.TempDir, job.JobUUID)
	if err := os.MkdirAll(tmpDir, 0o755); err != nil {
		return fmt.Errorf("create temp dir: %w", err)
	}

	defer func() {
		if err := os.RemoveAll(tmpDir); err != nil {
			slog.Warn("temp dir cleanup failed", "dir", tmpDir, "error", err)
		}
	}()

	result := payload.CallbackPayload{
		JobUUID:   job.JobUUID,
		VideoID:   job.VideoID,
		StartedAt: startedAt,
	}

	outputs, processingErr := w.processJob(jobCtx, job, tmpDir)

	result.CompletedAt = time.Now()

	if processingErr != nil {
		result.Status = "failed"
		result.ErrorMessage = processingErr.Error()
	} else {
		result.Status = "completed"
		result.Outputs = outputs
	}

	// Send callback using the parent ctx (not jobCtx) so a timed-out job can
	// still report its failure back to Laravel.
	if err := w.callback.Send(ctx, job.CallbackURL, result); err != nil {
		slog.Error("callback send failed", "job_uuid", job.JobUUID, "error", err)
	}

	if processingErr != nil {
		slog.Error("job failed", "job_uuid", job.JobUUID, "error", processingErr)
	} else {
		slog.Info("job completed", "job_uuid", job.JobUUID, "outputs", len(outputs))
	}

	return processingErr
}

// processJob runs the full pipeline for one job: download → probe → transcode → upload.
func (w *Worker) processJob(ctx context.Context, job payload.JobPayload, tmpDir string) ([]payload.OutputResult, error) {
	srcExt := filepath.Ext(job.SourceKey)
	if srcExt == "" {
		srcExt = ".mp4"
	}

	srcPath := filepath.Join(tmpDir, "source"+srcExt)

	if err := w.storage.Download(ctx, job.SourceBucket, job.SourceKey, srcPath); err != nil {
		return nil, fmt.Errorf("download source: %w", err)
	}

	slog.Info("source downloaded", "job_uuid", job.JobUUID, "path", srcPath)

	if _, err := w.transcoder.Probe(ctx, srcPath); err != nil {
		return nil, fmt.Errorf("probe: %w", err)
	}

	var outputs []payload.OutputResult

	for _, op := range job.Operations {
		opDir := filepath.Join(tmpDir, op.Type)
		if err := os.MkdirAll(opDir, 0o755); err != nil {
			return nil, fmt.Errorf("create dir for op %q: %w", op.Type, err)
		}

		outputPath, err := w.transcoder.RunOperation(ctx, srcPath, op, opDir)
		if err != nil {
			return nil, fmt.Errorf("operation %q: %w", op.Type, err)
		}

		outputKey := job.OutputKeyPrefix + filepath.Base(outputPath)

		size, err := w.storage.Upload(ctx, job.OutputBucket, outputKey, outputPath)
		if err != nil {
			return nil, fmt.Errorf("upload result for op %q: %w", op.Type, err)
		}

		outputs = append(outputs, payload.OutputResult{
			Operation:     op.Type,
			OutputKey:     outputKey,
			FileSizeBytes: size,
		})

		slog.Info("operation complete", "job_uuid", job.JobUUID, "type", op.Type, "key", outputKey)
	}

	return outputs, nil
}

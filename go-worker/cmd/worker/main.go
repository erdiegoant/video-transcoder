package main

import (
	"context"
	"log/slog"
	"os"
	"os/exec"
	"os/signal"
	"syscall"

	"videotrimmer/go-worker/internal/callback"
	"videotrimmer/go-worker/internal/config"
	"videotrimmer/go-worker/internal/queue"
	"videotrimmer/go-worker/internal/storage"
	"videotrimmer/go-worker/internal/transcoder"
	"videotrimmer/go-worker/internal/worker"
)

func main() {
	slog.SetDefault(slog.New(slog.NewJSONHandler(os.Stdout, nil)))

	cfg, err := config.Load()
	if err != nil {
		slog.Error("config error", "error", err)
		os.Exit(1)
	}

	if err := checkBinary(cfg.FFmpegPath); err != nil {
		slog.Error("ffmpeg not found", "path", cfg.FFmpegPath, "error", err)
		os.Exit(1)
	}

	if err := checkBinary(cfg.FFprobePath); err != nil {
		slog.Error("ffprobe not found", "path", cfg.FFprobePath, "error", err)
		os.Exit(1)
	}

	q, err := queue.New(cfg.RedisURL, cfg.RedisQueue)
	if err != nil {
		slog.Error("redis init failed", "error", err)
		os.Exit(1)
	}
	defer q.Close()

	s, err := storage.New(cfg)
	if err != nil {
		slog.Error("minio init failed", "error", err)
		os.Exit(1)
	}

	t := transcoder.NewRunner(cfg.FFmpegPath, cfg.FFprobePath, cfg.TempDir)
	c := callback.New(cfg.CallbackSecret, cfg.WorkerID)

	w := worker.New(cfg, q, s, t, c)

	// signal.NotifyContext cancels ctx when SIGTERM or SIGINT is received.
	// This is the K8s-friendly shutdown path: the pod sends SIGTERM, ctx is
	// cancelled, every goroutine finishes its current job, then the process exits.
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGTERM, syscall.SIGINT)
	defer stop()

	slog.Info("worker starting",
		"worker_id", cfg.WorkerID,
		"concurrency", cfg.MaxConcurrent,
		"queue", cfg.RedisQueue,
	)

	w.Start(ctx)

	slog.Info("worker shut down cleanly")
}

// checkBinary verifies a binary exists and is executable by running it with -version.
func checkBinary(path string) error {
	return exec.Command(path, "-version").Run()
}

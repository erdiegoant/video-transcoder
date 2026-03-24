package config

import (
	"testing"
)

func setRequiredEnv(t *testing.T) {
	t.Helper()
	t.Setenv("REDIS_URL", "redis://redis:6379")
	t.Setenv("MINIO_ENDPOINT", "minio:9000")
	t.Setenv("MINIO_ACCESS_KEY", "minioadmin")
	t.Setenv("MINIO_SECRET_KEY", "minioadmin")
	t.Setenv("CALLBACK_SECRET", "test-secret")
}

func TestLoad_AllVarsPresent(t *testing.T) {
	setRequiredEnv(t)

	cfg, err := Load()
	if err != nil {
		t.Fatalf("expected no error, got: %v", err)
	}

	if cfg.RedisURL != "redis://redis:6379" {
		t.Errorf("RedisURL = %q, want %q", cfg.RedisURL, "redis://redis:6379")
	}
	if cfg.RedisQueue != "queue:transcode" {
		t.Errorf("RedisQueue = %q, want default %q", cfg.RedisQueue, "queue:transcode")
	}
	if cfg.MaxConcurrent != 4 {
		t.Errorf("MaxConcurrent = %d, want default 4", cfg.MaxConcurrent)
	}
	if cfg.JobTimeoutSecs != 300 {
		t.Errorf("JobTimeoutSecs = %d, want default 300", cfg.JobTimeoutSecs)
	}
	if cfg.FFmpegPath != "ffmpeg" {
		t.Errorf("FFmpegPath = %q, want default %q", cfg.FFmpegPath, "ffmpeg")
	}
}

func TestLoad_OverridesDefaults(t *testing.T) {
	setRequiredEnv(t)
	t.Setenv("REDIS_QUEUE", "queue:custom")
	t.Setenv("MAX_CONCURRENT", "8")
	t.Setenv("JOB_TIMEOUT_SECS", "600")
	t.Setenv("MINIO_USE_SSL", "true")
	t.Setenv("WORKER_ID", "pod-abc123")

	cfg, err := Load()
	if err != nil {
		t.Fatalf("expected no error, got: %v", err)
	}

	if cfg.RedisQueue != "queue:custom" {
		t.Errorf("RedisQueue = %q, want %q", cfg.RedisQueue, "queue:custom")
	}
	if cfg.MaxConcurrent != 8 {
		t.Errorf("MaxConcurrent = %d, want 8", cfg.MaxConcurrent)
	}
	if cfg.JobTimeoutSecs != 600 {
		t.Errorf("JobTimeoutSecs = %d, want 600", cfg.JobTimeoutSecs)
	}
	if !cfg.MinioUseSSL {
		t.Error("MinioUseSSL = false, want true")
	}
	if cfg.WorkerID != "pod-abc123" {
		t.Errorf("WorkerID = %q, want %q", cfg.WorkerID, "pod-abc123")
	}
}

func TestLoad_MissingRequiredVars(t *testing.T) {
	// Don't set any env vars — all required vars are missing.
	_, err := Load()
	if err == nil {
		t.Fatal("expected error for missing required vars, got nil")
	}
}

func TestLoad_MissingOneRequiredVar(t *testing.T) {
	setRequiredEnv(t)
	t.Setenv("REDIS_URL", "") // unset one required var

	_, err := Load()
	if err == nil {
		t.Fatal("expected error for missing REDIS_URL, got nil")
	}
}

func TestLoad_InvalidIntVar(t *testing.T) {
	setRequiredEnv(t)
	t.Setenv("MAX_CONCURRENT", "not-a-number")

	_, err := Load()
	if err == nil {
		t.Fatal("expected error for invalid MAX_CONCURRENT, got nil")
	}
}

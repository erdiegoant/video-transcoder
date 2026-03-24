package config

import (
	"errors"
	"fmt"
	"os"
	"strconv"
)

// Config holds all runtime configuration read from environment variables.
type Config struct {
	RedisURL       string
	RedisQueue     string // default: "queue:transcode"
	MinioEndpoint  string
	MinioAccessKey string
	MinioSecretKey string
	MinioUseSSL    bool
	WorkerID       string // identifies which instance processed a job (pod name in K8s)
	FFmpegPath     string // default: "ffmpeg"
	FFprobePath    string // default: "ffprobe"
	TempDir        string // working directory for intermediate FFmpeg files
	MaxConcurrent  int    // number of jobs processed simultaneously
	JobTimeoutSecs int    // max seconds a single FFmpeg run may take
}

// Load reads environment variables and returns a populated Config.
// Returns an error if any required variable is missing or invalid.
func Load() (*Config, error) {
	var errs []error

	required := func(key string) string {
		val := os.Getenv(key)
		if val == "" {
			errs = append(errs, fmt.Errorf("required env var %s is not set", key))
		}
		return val
	}

	optional := func(key, fallback string) string {
		if val := os.Getenv(key); val != "" {
			return val
		}
		return fallback
	}

	optionalInt := func(key string, fallback int) int {
		val := os.Getenv(key)
		if val == "" {
			return fallback
		}
		n, err := strconv.Atoi(val)
		if err != nil {
			errs = append(errs, fmt.Errorf("env var %s must be an integer, got %q", key, val))
			return fallback
		}
		return n
	}

	workerID, _ := os.Hostname()

	cfg := &Config{
		RedisURL:       required("REDIS_URL"),
		RedisQueue:     optional("REDIS_QUEUE", "queue:transcode"),
		MinioEndpoint:  required("MINIO_ENDPOINT"),
		MinioAccessKey: required("MINIO_ACCESS_KEY"),
		MinioSecretKey: required("MINIO_SECRET_KEY"),
		MinioUseSSL:    os.Getenv("MINIO_USE_SSL") == "true",
		WorkerID:       optional("WORKER_ID", workerID),
		FFmpegPath:     optional("FFMPEG_PATH", "ffmpeg"),
		FFprobePath:    optional("FFPROBE_PATH", "ffprobe"),
		TempDir:        optional("TEMP_DIR", os.TempDir()),
		MaxConcurrent:  optionalInt("MAX_CONCURRENT", 4),
		JobTimeoutSecs: optionalInt("JOB_TIMEOUT_SECS", 300),
	}

	if len(errs) > 0 {
		return nil, errors.Join(errs...)
	}

	return cfg, nil
}

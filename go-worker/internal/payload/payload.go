package payload

import "time"

// JobPayload is the structure Laravel pushes onto the Redis queue.
// Go reads this when it pops a job off the queue.
type JobPayload struct {
	JobUUID         string      `json:"job_uuid"`
	VideoID         int64       `json:"video_id"`
	UserID          int64       `json:"user_id"`
	SourceBucket    string      `json:"source_bucket"`
	SourceKey       string      `json:"source_key"`
	OutputBucket    string      `json:"output_bucket"`
	OutputKeyPrefix string      `json:"output_key_prefix"`
	Operations      []Operation `json:"operations"`
	CallbackURL     string      `json:"callback_url"`
	MaxAttempts     int         `json:"max_attempts"`
	EnqueuedAt      time.Time   `json:"enqueued_at"`
}

// Operation describes a single FFmpeg task within a job.
// Type is one of: "transcode", "thumbnail", "trim".
type Operation struct {
	Type        string  `json:"type"`
	Format      string  `json:"format,omitempty"`
	Resolution  string  `json:"resolution,omitempty"`
	CRF         int     `json:"crf,omitempty"`
	AtSecond    float64 `json:"at_second,omitempty"`
	TrimStart   float64 `json:"trim_start,omitempty"`
	TrimEnd     float64 `json:"trim_end,omitempty"`
}

// CallbackPayload is what Go POSTs to Laravel's webhook after processing.
type CallbackPayload struct {
	JobUUID      string         `json:"job_uuid"`
	VideoID      int64          `json:"video_id"`
	Status       string         `json:"status"` // "completed" or "failed"
	WorkerID     string         `json:"worker_id"`
	Outputs      []OutputResult `json:"outputs"`
	ErrorMessage string         `json:"error_message"`
	StartedAt    time.Time      `json:"started_at"`
	CompletedAt  time.Time      `json:"completed_at"`
}

// OutputResult describes one processed file produced by an operation.
type OutputResult struct {
	Operation       string  `json:"operation"`
	OutputKey       string  `json:"output_key"`
	FileSizeBytes   int64   `json:"file_size_bytes"`
	DurationSeconds float64 `json:"duration_seconds,omitempty"`
	Width           int     `json:"width,omitempty"`
	Height          int     `json:"height,omitempty"`
}

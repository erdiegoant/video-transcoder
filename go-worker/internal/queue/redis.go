package queue

import (
	"context"
	"fmt"
	"time"

	"github.com/redis/go-redis/v9"
)

// Consumer pulls jobs from a Redis list using BLMOVE for reliable delivery.
//
// The "processing" list acts as an in-flight tracker: a job is atomically
// moved from the source queue into the processing list before work begins,
// and removed only after success (Ack) or requeued on failure (Nack).
// If the worker crashes mid-job, the job stays in the processing list and
// can be recovered by a separate reconciler (Laravel's ReconcileStuckJobs).
type Consumer struct {
	client        *redis.Client
	sourceQueue   string // e.g. "queue:transcode"
	processingKey string // e.g. "queue:transcode:processing"
}

// New creates a Consumer connected to the given Redis URL.
// URL format: "redis://<host>:<port>" or "redis://:<password>@<host>:<port>"
func New(redisURL, queueName string) (*Consumer, error) {
	opts, err := redis.ParseURL(redisURL)
	if err != nil {
		return nil, fmt.Errorf("invalid redis URL: %w", err)
	}

	return &Consumer{
		client:        redis.NewClient(opts),
		sourceQueue:   queueName,
		processingKey: queueName + ":processing",
	}, nil
}

// Pop blocks until a job is available, then atomically moves it from the
// source queue into the processing list and returns the raw bytes.
//
// Uses BLMOVE (blocking LMOVE), the modern replacement for the deprecated
// BRPOPLPUSH. "RIGHT LEFT" means: take from the right end of the source
// queue, push to the left end of the processing list.
func (c *Consumer) Pop(ctx context.Context) ([]byte, error) {
	result, err := c.client.BLMove(ctx, c.sourceQueue, c.processingKey, "RIGHT", "LEFT", 5*time.Second).Bytes()
	if err != nil {
		return nil, fmt.Errorf("BLMOVE failed: %w", err)
	}

	return result, nil
}

// Ack removes the job from the processing list after successful completion.
func (c *Consumer) Ack(ctx context.Context, payload []byte) error {
	removed, err := c.client.LRem(ctx, c.processingKey, 1, payload).Result()
	if err != nil {
		return fmt.Errorf("LREM (ack) failed: %w", err)
	}

	if removed == 0 {
		return fmt.Errorf("ack: job not found in processing list")
	}

	return nil
}

// Nack removes the job from the processing list and pushes it back onto the
// front of the source queue so it will be retried next.
func (c *Consumer) Nack(ctx context.Context, payload []byte) error {
	pipe := c.client.Pipeline()
	pipe.LRem(ctx, c.processingKey, 1, payload)
	pipe.LPush(ctx, c.sourceQueue, payload)

	if _, err := pipe.Exec(ctx); err != nil {
		return fmt.Errorf("NACK pipeline failed: %w", err)
	}

	return nil
}

// Close shuts down the Redis connection.
func (c *Consumer) Close() error {
	return c.client.Close()
}

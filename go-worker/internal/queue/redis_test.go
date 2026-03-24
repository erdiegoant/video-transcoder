package queue

import (
	"context"
	"testing"
	"time"

	"github.com/alicebob/miniredis/v2"
	"github.com/redis/go-redis/v9"
)

// newTestConsumer starts an in-memory Redis server and returns a Consumer
// wired to it. The server is stopped automatically when the test ends.
func newTestConsumer(t *testing.T) (*Consumer, *miniredis.Miniredis) {
	t.Helper()

	mr := miniredis.RunT(t)

	c := &Consumer{
		client:        redis.NewClient(&redis.Options{Addr: mr.Addr()}),
		sourceQueue:   "queue:transcode",
		processingKey: "queue:transcode:processing",
	}

	return c, mr
}

func TestPop_MovesJobToProcessingList(t *testing.T) {
	c, mr := newTestConsumer(t)
	ctx := context.Background()

	mr.Lpush("queue:transcode", `{"job_uuid":"abc"}`)

	payload, err := c.Pop(ctx)
	if err != nil {
		t.Fatalf("Pop() error: %v", err)
	}

	if string(payload) != `{"job_uuid":"abc"}` {
		t.Errorf("Pop() = %q, want %q", payload, `{"job_uuid":"abc"}`)
	}

	// Job must now be in the processing list, not the source queue.
	source, _ := mr.List("queue:transcode")
	processing, _ := mr.List("queue:transcode:processing")

	if len(source) != 0 {
		t.Errorf("source queue len = %d, want 0", len(source))
	}
	if len(processing) != 1 {
		t.Errorf("processing list len = %d, want 1", len(processing))
	}
}

func TestAck_RemovesJobFromProcessingList(t *testing.T) {
	c, mr := newTestConsumer(t)
	ctx := context.Background()

	mr.Lpush("queue:transcode", `{"job_uuid":"abc"}`)

	payload, _ := c.Pop(ctx)

	if err := c.Ack(ctx, payload); err != nil {
		t.Fatalf("Ack() error: %v", err)
	}

	processing, _ := mr.List("queue:transcode:processing")
	if len(processing) != 0 {
		t.Errorf("processing list len = %d, want 0 after ack", len(processing))
	}
}

func TestNack_RequeuesJobOntoSourceQueue(t *testing.T) {
	c, mr := newTestConsumer(t)
	ctx := context.Background()

	mr.Lpush("queue:transcode", `{"job_uuid":"abc"}`)

	payload, _ := c.Pop(ctx)

	if err := c.Nack(ctx, payload); err != nil {
		t.Fatalf("Nack() error: %v", err)
	}

	source, _ := mr.List("queue:transcode")
	processing, _ := mr.List("queue:transcode:processing")

	if len(source) != 1 {
		t.Errorf("source queue len = %d, want 1 after nack", len(source))
	}
	if len(processing) != 0 {
		t.Errorf("processing list len = %d, want 0 after nack", len(processing))
	}
}

func TestPop_BlocksUntilJobArrives(t *testing.T) {
	c, mr := newTestConsumer(t)
	ctx := context.Background()

	// Push a job after a short delay from a separate goroutine.
	go func() {
		time.Sleep(100 * time.Millisecond)
		mr.Lpush("queue:transcode", `{"job_uuid":"delayed"}`)
	}()

	payload, err := c.Pop(ctx)
	if err != nil {
		t.Fatalf("Pop() error: %v", err)
	}

	if string(payload) != `{"job_uuid":"delayed"}` {
		t.Errorf("Pop() = %q, want %q", payload, `{"job_uuid":"delayed"}`)
	}
}

func TestPop_ReturnsErrorOnContextCancel(t *testing.T) {
	c, _ := newTestConsumer(t)

	ctx, cancel := context.WithCancel(context.Background())
	cancel() // cancel immediately — no job to pop

	_, err := c.Pop(ctx)
	if err == nil {
		t.Fatal("Pop() expected error on cancelled context, got nil")
	}
}

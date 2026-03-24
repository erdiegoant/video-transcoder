package callback

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"videotrimmer/go-worker/internal/payload"
)

// newTestClient returns a Client with zero backoff delays so retries are instant.
func newTestClient(secret string) *Client {
	c := New(secret, "test-worker")
	c.backoff = []time.Duration{0, 0, 0}
	return c
}

func testPayload() payload.CallbackPayload {
	return payload.CallbackPayload{
		JobUUID:  "abc-123",
		VideoID:  1,
		Status:   "completed",
		Outputs:  []payload.OutputResult{},
		StartedAt:   time.Now(),
		CompletedAt: time.Now(),
	}
}

func TestSend_Success(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
	}))
	defer server.Close()

	c := newTestClient("secret")

	if err := c.Send(context.Background(), server.URL, testPayload()); err != nil {
		t.Fatalf("Send() error = %v", err)
	}
}

func TestSend_HMACSignatureCorrect(t *testing.T) {
	const secret = "test-secret"

	var receivedSig string

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		receivedSig = r.Header.Get("X-Signature")

		// Verify the signature against the body using the same secret.
		var body bytes.Buffer
		_, _ = body.ReadFrom(r.Body)

		mac := hmac.New(sha256.New, []byte(secret))
		mac.Write(body.Bytes())
		expected := "sha256=" + hex.EncodeToString(mac.Sum(nil))

		if receivedSig != expected {
			w.WriteHeader(http.StatusUnauthorized)
			return
		}
		w.WriteHeader(http.StatusOK)
	}))
	defer server.Close()

	c := newTestClient(secret)

	if err := c.Send(context.Background(), server.URL, testPayload()); err != nil {
		t.Fatalf("Send() error = %v, signature = %q", err, receivedSig)
	}

	if !strings.HasPrefix(receivedSig, "sha256=") {
		t.Errorf("X-Signature = %q, want sha256= prefix", receivedSig)
	}
}

func TestSend_RetriesOn5xx(t *testing.T) {
	var attempts atomic.Int32

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		n := attempts.Add(1)
		if n < 3 {
			w.WriteHeader(http.StatusInternalServerError)
			return
		}
		w.WriteHeader(http.StatusOK)
	}))
	defer server.Close()

	c := newTestClient("secret")

	if err := c.Send(context.Background(), server.URL, testPayload()); err != nil {
		t.Fatalf("Send() error = %v", err)
	}

	if attempts.Load() != 3 {
		t.Errorf("attempts = %d, want 3", attempts.Load())
	}
}

func TestSend_GivesUpAfterMaxRetries(t *testing.T) {
	var attempts atomic.Int32

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		attempts.Add(1)
		w.WriteHeader(http.StatusInternalServerError)
	}))
	defer server.Close()

	c := newTestClient("secret")

	err := c.Send(context.Background(), server.URL, testPayload())
	if err == nil {
		t.Fatal("Send() expected error after max retries, got nil")
	}

	// defaultBackoff has 3 entries → 4 total attempts (1 initial + 3 retries).
	if attempts.Load() != 4 {
		t.Errorf("attempts = %d, want 4", attempts.Load())
	}
}

func TestSend_DoesNotRetryOn4xx(t *testing.T) {
	var attempts atomic.Int32

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		attempts.Add(1)
		w.WriteHeader(http.StatusBadRequest)
	}))
	defer server.Close()

	c := newTestClient("secret")

	err := c.Send(context.Background(), server.URL, testPayload())
	if err == nil {
		t.Fatal("Send() expected error on 4xx, got nil")
	}

	if attempts.Load() != 1 {
		t.Errorf("attempts = %d, want 1 (no retry on 4xx)", attempts.Load())
	}
}

func TestSend_WorkerIDIsSetInPayload(t *testing.T) {
	var received payload.CallbackPayload

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_ = json.NewDecoder(r.Body).Decode(&received)
		w.WriteHeader(http.StatusOK)
	}))
	defer server.Close()

	c := newTestClient("secret")

	if err := c.Send(context.Background(), server.URL, testPayload()); err != nil {
		t.Fatalf("Send() error = %v", err)
	}

	if received.WorkerID != "test-worker" {
		t.Errorf("WorkerID = %q, want %q", received.WorkerID, "test-worker")
	}
}

package callback

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"net/http"
	"time"

	"videotrimmer/go-worker/internal/payload"
)

// fatalErr wraps an error that should not be retried (e.g. 4xx responses).
type fatalErr struct{ err error }

func (e *fatalErr) Error() string { return e.err.Error() }
func (e *fatalErr) Unwrap() error { return e.err }

// defaultBackoff is the wait time before each retry attempt.
// Attempt 1 fails → wait 5s → attempt 2 fails → wait 30s → attempt 3 fails → give up.
var defaultBackoff = []time.Duration{5 * time.Second, 30 * time.Second, 5 * time.Minute}

// Client sends signed webhook callbacks to Laravel.
type Client struct {
	httpClient *http.Client
	secret     string
	workerID   string
	backoff    []time.Duration // injectable for testing
}

// New creates a Client. Each HTTP attempt has a 10-second timeout.
func New(secret, workerID string) *Client {
	return &Client{
		httpClient: &http.Client{Timeout: 10 * time.Second},
		secret:     secret,
		workerID:   workerID,
		backoff:    defaultBackoff,
	}
}

// Send POSTs the callback payload to the given URL, retrying on 5xx or network
// errors. Returns an error only if all attempts fail.
func (c *Client) Send(ctx context.Context, url string, result payload.CallbackPayload) error {
	result.WorkerID = c.workerID

	body, err := json.Marshal(result)
	if err != nil {
		return fmt.Errorf("callback marshal failed: %w", err)
	}

	sig := sign(body, c.secret)

	var lastErr error

	for attempt := 0; attempt <= len(c.backoff); attempt++ {
		if attempt > 0 {
			select {
			case <-ctx.Done():
				return fmt.Errorf("callback cancelled after %d attempt(s): %w", attempt, ctx.Err())
			case <-time.After(c.backoff[attempt-1]):
			}
		}

		lastErr = c.post(ctx, url, body, sig)
		if lastErr == nil {
			return nil
		}
		if _, ok := lastErr.(*fatalErr); ok {
			return lastErr
		}
	}

	return fmt.Errorf("callback failed after %d attempt(s): %w", len(c.backoff)+1, lastErr)
}

// post performs a single HTTP POST attempt.
func (c *Client) post(ctx context.Context, url string, body []byte, sig string) error {
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, url, bytes.NewReader(body))
	if err != nil {
		return fmt.Errorf("build request: %w", err)
	}

	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-Signature", "sha256="+sig)

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return fmt.Errorf("http request: %w", err)
	}
	defer func() { _ = resp.Body.Close() }()

	if resp.StatusCode >= 500 {
		return fmt.Errorf("server error: %s", resp.Status)
	}

	if resp.StatusCode >= 400 {
		// 4xx errors are not retried — a bad request won't fix itself.
		return &fatalErr{fmt.Errorf("client error (not retried): %s", resp.Status)}
	}

	return nil
}

// sign computes HMAC-SHA256(body, secret) and returns it as a lowercase hex string.
func sign(body []byte, secret string) string {
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write(body)
	return hex.EncodeToString(mac.Sum(nil))
}

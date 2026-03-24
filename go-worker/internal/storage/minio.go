package storage

import (
	"context"
	"fmt"

	"github.com/minio/minio-go/v7"
	"github.com/minio/minio-go/v7/pkg/credentials"

	"videotrimmer/go-worker/internal/config"
)

// Client wraps the MinIO SDK with the operations the worker needs.
type Client struct {
	mc *minio.Client
}

// New creates a storage Client from the loaded config.
func New(cfg *config.Config) (*Client, error) {
	mc, err := minio.New(cfg.MinioEndpoint, &minio.Options{
		Creds:  credentials.NewStaticV4(cfg.MinioAccessKey, cfg.MinioSecretKey, ""),
		Secure: cfg.MinioUseSSL,
	})
	if err != nil {
		return nil, fmt.Errorf("minio client init: %w", err)
	}

	return &Client{mc: mc}, nil
}

// Download streams an object from MinIO and writes it to destPath on disk.
func (c *Client) Download(ctx context.Context, bucket, key, destPath string) error {
	err := c.mc.FGetObject(ctx, bucket, key, destPath, minio.GetObjectOptions{})
	if err != nil {
		return fmt.Errorf("download %s/%s: %w", bucket, key, err)
	}

	return nil
}

// Upload writes a local file at srcPath to MinIO and returns the file size in bytes.
func (c *Client) Upload(ctx context.Context, bucket, key, srcPath string) (int64, error) {
	info, err := c.mc.FPutObject(ctx, bucket, key, srcPath, minio.PutObjectOptions{})
	if err != nil {
		return 0, fmt.Errorf("upload %s/%s: %w", bucket, key, err)
	}

	return info.Size, nil
}

// Delete removes an object from MinIO.
func (c *Client) Delete(ctx context.Context, bucket, key string) error {
	err := c.mc.RemoveObject(ctx, bucket, key, minio.RemoveObjectOptions{})
	if err != nil {
		return fmt.Errorf("delete %s/%s: %w", bucket, key, err)
	}

	return nil
}

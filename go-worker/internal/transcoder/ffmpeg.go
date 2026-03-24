package transcoder

import (
	"bytes"
	"context"
	"fmt"
	"os/exec"
	"path/filepath"

	"videotrimmer/go-worker/internal/payload"
)

// Runner executes FFmpeg commands.
type Runner struct {
	ffmpegPath  string
	ffprobePath string
	tempDir     string
}

// NewRunner creates a Runner using paths from config.
func NewRunner(ffmpegPath, ffprobePath, tempDir string) *Runner {
	return &Runner{
		ffmpegPath:  ffmpegPath,
		ffprobePath: ffprobePath,
		tempDir:     tempDir,
	}
}

// Run executes FFmpeg with the given argument list.
// When ctx is cancelled, the FFmpeg child process is killed automatically.
func (r *Runner) Run(ctx context.Context, args []string) error {
	var stderr bytes.Buffer

	cmd := exec.CommandContext(ctx, r.ffmpegPath, args...)
	cmd.Stderr = &stderr

	if err := cmd.Run(); err != nil {
		return fmt.Errorf("ffmpeg error: %w\nstderr: %s", err, stderr.String())
	}

	return nil
}

// RunOperation builds the FFmpeg args for one operation, runs FFmpeg, and
// returns the path of the output file it created inside outputDir.
func (r *Runner) RunOperation(ctx context.Context, inputPath string, op payload.Operation, outputDir string) (string, error) {
	outputPath, err := outputPathFor(op, outputDir, inputPath)
	if err != nil {
		return "", err
	}

	args, err := buildArgs(inputPath, outputPath, op)
	if err != nil {
		return "", err
	}

	if err := r.Run(ctx, args); err != nil {
		return "", fmt.Errorf("operation %q failed: %w", op.Type, err)
	}

	return outputPath, nil
}

// Probe delegates to the package-level Probe function using this runner's ffprobe path.
func (r *Runner) Probe(ctx context.Context, filePath string) (*VideoInfo, error) {
	return Probe(ctx, r.ffprobePath, filePath)
}

// buildArgs dispatches to the correct Build*Args function based on operation type.
func buildArgs(input, output string, op payload.Operation) ([]string, error) {
	switch op.Type {
	case "transcode":
		return BuildTranscodeArgs(input, output, op)
	case "thumbnail":
		return BuildThumbnailArgs(input, output, op)
	case "trim":
		return BuildTrimArgs(input, output, op)
	default:
		return nil, fmt.Errorf("unknown operation type %q", op.Type)
	}
}

// outputPathFor determines the output filename for a given operation.
func outputPathFor(op payload.Operation, outputDir, inputPath string) (string, error) {
	switch op.Type {
	case "transcode":
		if op.Format == "" {
			return "", fmt.Errorf("transcode operation missing format")
		}
		return filepath.Join(outputDir, "video."+op.Format), nil

	case "thumbnail":
		format := op.Format
		if format == "" {
			format = "jpg"
		}
		return filepath.Join(outputDir, "thumb."+format), nil

	case "trim":
		ext := filepath.Ext(inputPath)
		if ext == "" {
			ext = ".mp4"
		}
		return filepath.Join(outputDir, "trimmed"+ext), nil

	default:
		return "", fmt.Errorf("unknown operation type %q", op.Type)
	}
}

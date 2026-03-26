package transcoder

import (
	"fmt"
	"strconv"
	"strings"

	"videotrimmer/go-worker/internal/payload"
)

const defaultCRF = 28

// BuildTranscodeArgs returns the FFmpeg argument list for a transcode operation.
// Supports output formats: mp4, webm, mkv, mov.
//
// Example output:
//
//	["-i", "input.mp4", "-c:v", "libvpx-vp9", "-crf", "28", "-b:v", "0", "-vf", "scale=1280:720", "output.webm"]
func BuildTranscodeArgs(input, output string, op payload.Operation) ([]string, error) {
	codec, err := codecForFormat(op.Format)
	if err != nil {
		return nil, err
	}

	crf := op.CRF
	if crf == 0 {
		crf = defaultCRF
	}

	args := []string{"-i", input, "-c:v", codec, "-crf", strconv.Itoa(crf)}

	// VP9 requires -b:v 0 to use constant quality mode (CRF only, no bitrate cap).
	if op.Format == "webm" {
		args = append(args, "-b:v", "0")
	}

	if op.Resolution != "" {
		scale, err := resolutionToScale(op.Resolution)
		if err != nil {
			return nil, err
		}

		args = append(args, "-vf", scale)
	}

	args = append(args, output)

	return args, nil
}

// BuildThumbnailArgs returns FFmpeg args to extract a single frame as an image.
//
// Example output:
//
//	["-i", "input.mp4", "-ss", "3.000000", "-vframes", "1", "thumb.jpg"]
func BuildThumbnailArgs(input, output string, op payload.Operation) ([]string, error) {
	if op.AtSecond < 0 {
		return nil, fmt.Errorf("thumbnail at_second must be >= 0, got %f", op.AtSecond)
	}

	return []string{
		"-i", input,
		"-ss", fmt.Sprintf("%f", op.AtSecond),
		"-vframes", "1",
		output,
	}, nil
}

// BuildTrimArgs returns FFmpeg args to trim a video using stream copy (no re-encoding).
//
// Example output:
//
//	["-i", "input.mp4", "-ss", "10.000000", "-to", "60.000000", "-c", "copy", "trimmed.mp4"]
func BuildTrimArgs(input, output string, op payload.Operation) ([]string, error) {
	if op.TrimEnd > 0 && op.TrimEnd <= op.TrimStart {
		return nil, fmt.Errorf("trim_end (%f) must be greater than trim_start (%f)", op.TrimEnd, op.TrimStart)
	}

	args := []string{
		"-i", input,
		"-ss", fmt.Sprintf("%f", op.TrimStart),
	}

	if op.TrimEnd > 0 {
		args = append(args, "-to", fmt.Sprintf("%f", op.TrimEnd))
	}

	args = append(args, "-c", "copy", output)

	return args, nil
}

// codecForFormat maps an output format name to the FFmpeg codec flag value.
func codecForFormat(format string) (string, error) {
	switch strings.ToLower(format) {
	case "mp4", "mov", "mkv":
		return "libx264", nil
	case "webm":
		return "libvpx-vp9", nil
	default:
		return "", fmt.Errorf("unsupported format %q: must be mp4, webm, mkv, or mov", format)
	}
}

// resolutionToScale converts "1280x720" to the FFmpeg scale filter "scale=1280:720".
func resolutionToScale(resolution string) (string, error) {
	parts := strings.SplitN(resolution, "x", 2)
	if len(parts) != 2 {
		return "", fmt.Errorf("invalid resolution %q: expected format WxH e.g. 1280x720", resolution)
	}

	w, errW := strconv.Atoi(parts[0])
	h, errH := strconv.Atoi(parts[1])

	if errW != nil || errH != nil || w <= 0 || h <= 0 {
		return "", fmt.Errorf("invalid resolution %q: width and height must be positive integers", resolution)
	}

	return fmt.Sprintf("scale=%d:%d", w, h), nil
}

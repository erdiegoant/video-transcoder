package transcoder

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"os/exec"
	"strconv"
)

// VideoInfo holds the metadata extracted from a video file by ffprobe.
type VideoInfo struct {
	DurationSeconds float64
	Width           int
	Height          int
	Format          string
	BitrateBps      int64
}

// ffprobe JSON output shapes — used only for parsing, not exported.
type ffprobeOutput struct {
	Streams []ffprobeStream `json:"streams"`
	Format  ffprobeFormat   `json:"format"`
}

type ffprobeStream struct {
	CodecType string `json:"codec_type"`
	CodecName string `json:"codec_name"`
	Width     int    `json:"width"`
	Height    int    `json:"height"`
	Duration  string `json:"duration"` // ffprobe returns duration as a string
	BitRate   string `json:"bit_rate"`
}

type ffprobeFormat struct {
	FormatName string `json:"format_name"`
	Duration   string `json:"duration"`
	BitRate    string `json:"bit_rate"`
}

// Probe runs ffprobe on the given file and returns its video metadata.
func Probe(ctx context.Context, ffprobePath, filePath string) (*VideoInfo, error) {
	var stdout, stderr bytes.Buffer

	cmd := exec.CommandContext(ctx, ffprobePath,
		"-v", "quiet",
		"-print_format", "json",
		"-show_streams",
		"-show_format",
		filePath,
	)
	cmd.Stdout = &stdout
	cmd.Stderr = &stderr

	if err := cmd.Run(); err != nil {
		return nil, fmt.Errorf("ffprobe failed: %w\nstderr: %s", err, stderr.String())
	}

	var out ffprobeOutput
	if err := json.Unmarshal(stdout.Bytes(), &out); err != nil {
		return nil, fmt.Errorf("ffprobe output parse failed: %w", err)
	}

	info := &VideoInfo{
		Format: out.Format.FormatName,
	}

	// Parse duration and bitrate from the format section (most reliable source).
	info.DurationSeconds, _ = strconv.ParseFloat(out.Format.Duration, 64)
	bitrate, _ := strconv.ParseInt(out.Format.BitRate, 10, 64)
	info.BitrateBps = bitrate

	// Find the first video stream for width/height.
	for _, s := range out.Streams {
		if s.CodecType == "video" {
			info.Width = s.Width
			info.Height = s.Height
			break
		}
	}

	return info, nil
}

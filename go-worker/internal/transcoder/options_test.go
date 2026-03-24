package transcoder

import (
	"reflect"
	"testing"

	"videotrimmer/go-worker/internal/payload"
)

func TestBuildTranscodeArgs(t *testing.T) {
	tests := []struct {
		name    string
		op      payload.Operation
		want    []string
		wantErr bool
	}{
		{
			name: "webm with resolution",
			op:   payload.Operation{Format: "webm", Resolution: "1280x720", CRF: 28},
			want: []string{"-i", "in.mp4", "-c:v", "libvpx-vp9", "-crf", "28", "-b:v", "0", "-vf", "scale=1280:720", "out.webm"},
		},
		{
			name: "mp4 with resolution",
			op:   payload.Operation{Format: "mp4", Resolution: "1920x1080", CRF: 23},
			want: []string{"-i", "in.mp4", "-c:v", "libx264", "-crf", "23", "-vf", "scale=1920:1080", "out.webm"},
		},
		{
			name: "mp4 without resolution",
			op:   payload.Operation{Format: "mp4", CRF: 28},
			want: []string{"-i", "in.mp4", "-c:v", "libx264", "-crf", "28", "out.webm"},
		},
		{
			name: "uses default CRF when zero",
			op:   payload.Operation{Format: "mp4"},
			want: []string{"-i", "in.mp4", "-c:v", "libx264", "-crf", "28", "out.webm"},
		},
		{
			name:    "unsupported format returns error",
			op:      payload.Operation{Format: "avi"},
			wantErr: true,
		},
		{
			name:    "invalid resolution returns error",
			op:      payload.Operation{Format: "mp4", Resolution: "bad"},
			wantErr: true,
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			got, err := BuildTranscodeArgs("in.mp4", "out.webm", tt.op)

			if (err != nil) != tt.wantErr {
				t.Fatalf("BuildTranscodeArgs() error = %v, wantErr %v", err, tt.wantErr)
			}
			if !tt.wantErr && !reflect.DeepEqual(got, tt.want) {
				t.Errorf("BuildTranscodeArgs() =\n  %v\nwant\n  %v", got, tt.want)
			}
		})
	}
}

func TestBuildThumbnailArgs(t *testing.T) {
	tests := []struct {
		name    string
		op      payload.Operation
		want    []string
		wantErr bool
	}{
		{
			name: "extracts frame at 3 seconds",
			op:   payload.Operation{AtSecond: 3.0},
			want: []string{"-i", "in.mp4", "-ss", "3.000000", "-vframes", "1", "thumb.jpg"},
		},
		{
			name: "works at second zero",
			op:   payload.Operation{AtSecond: 0},
			want: []string{"-i", "in.mp4", "-ss", "0.000000", "-vframes", "1", "thumb.jpg"},
		},
		{
			name:    "negative second returns error",
			op:      payload.Operation{AtSecond: -1},
			wantErr: true,
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			got, err := BuildThumbnailArgs("in.mp4", "thumb.jpg", tt.op)

			if (err != nil) != tt.wantErr {
				t.Fatalf("BuildThumbnailArgs() error = %v, wantErr %v", err, tt.wantErr)
			}
			if !tt.wantErr && !reflect.DeepEqual(got, tt.want) {
				t.Errorf("BuildThumbnailArgs() =\n  %v\nwant\n  %v", got, tt.want)
			}
		})
	}
}

func TestBuildTrimArgs(t *testing.T) {
	tests := []struct {
		name    string
		op      payload.Operation
		want    []string
		wantErr bool
	}{
		{
			name: "trim with start and end",
			op:   payload.Operation{TrimStart: 10, TrimEnd: 60},
			want: []string{"-i", "in.mp4", "-ss", "10.000000", "-to", "60.000000", "-c", "copy", "out.mp4"},
		},
		{
			name: "trim with start only",
			op:   payload.Operation{TrimStart: 30},
			want: []string{"-i", "in.mp4", "-ss", "30.000000", "-c", "copy", "out.mp4"},
		},
		{
			name:    "end before start returns error",
			op:      payload.Operation{TrimStart: 60, TrimEnd: 10},
			wantErr: true,
		},
		{
			name:    "end equal to start returns error",
			op:      payload.Operation{TrimStart: 30, TrimEnd: 30},
			wantErr: true,
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			got, err := BuildTrimArgs("in.mp4", "out.mp4", tt.op)

			if (err != nil) != tt.wantErr {
				t.Fatalf("BuildTrimArgs() error = %v, wantErr %v", err, tt.wantErr)
			}
			if !tt.wantErr && !reflect.DeepEqual(got, tt.want) {
				t.Errorf("BuildTrimArgs() =\n  %v\nwant\n  %v", got, tt.want)
			}
		})
	}
}

# Stage 1: build the Go binary
FROM golang:1.26-alpine AS builder

WORKDIR /build

COPY go.mod go.sum ./
RUN go mod download

COPY . .
RUN CGO_ENABLED=0 GOOS=linux go build -o worker ./cmd/worker

# Stage 2: minimal runtime image with FFmpeg
FROM alpine:3.21

RUN apk add --no-cache ffmpeg

WORKDIR /app

COPY --from=builder /build/worker .

CMD ["./worker"]

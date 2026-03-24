# Phase 2 — Kubernetes (Production)

**Narrative for portfolio:** *"I started with Compose, but once I modeled the CPU spikes from concurrent FFmpeg jobs, I realized I needed HPA. The migration itself taught me how K8s resource requests/limits map to Go's GOMAXPROCS cgroup awareness — in Go 1.25, you get correct goroutine scheduling for free inside K8s CPU limits."*

---

## Step 2.1 — Harden Dockerfiles

**Status: NOT STARTED**

### Laravel `Dockerfile` (multi-stage)

```dockerfile
FROM composer:2 AS deps
WORKDIR /app
COPY laravel-app/composer.* .
RUN composer install --no-dev --optimize-autoloader

FROM php:8.5-fpm-alpine AS runtime
RUN docker-php-ext-install pdo pdo_pgsql opcache
COPY --from=deps /app/vendor /var/www/html/vendor
COPY laravel-app/ /var/www/html/
RUN echo "opcache.enable=1\nopcache.memory_consumption=256" >> /usr/local/etc/php/conf.d/opcache.ini
```

### Go `Dockerfile` (multi-stage → minimal image)

```dockerfile
FROM golang:1.25-alpine AS builder
WORKDIR /app
COPY go.mod go.sum ./
RUN go mod download
COPY . .
RUN CGO_ENABLED=0 GOOS=linux go build -ldflags="-s -w" -o /worker ./cmd/worker

FROM alpine:3.21 AS runtime
# FFmpeg must be in the runtime image
RUN apk add --no-cache ffmpeg
COPY --from=builder /worker /worker
ENTRYPOINT ["/worker"]
```

**Why alpine (not scratch)?** FFmpeg has runtime dependencies (shared libs). `scratch` won't work here. Alpine is the next best thing at ~20MB.

---

## Step 2.2 — Kubernetes Manifests

**Status: NOT STARTED**

### `go-worker-deployment.yaml`

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: go-worker
  namespace: videotrimmer
spec:
  replicas: 1                          # HPA will manage this
  selector:
    matchLabels:
      app: go-worker
  template:
    metadata:
      labels:
        app: go-worker
      annotations:
        prometheus.io/scrape: "true"
        prometheus.io/port: "9090"
    spec:
      containers:
        - name: go-worker
          image: your-registry/go-worker:latest
          resources:
            requests:
              cpu: "500m"
              memory: "256Mi"
            limits:
              cpu: "2000m"
              memory: "1Gi"
          env:
            - name: REDIS_URL
              valueFrom:
                secretKeyRef:
                  name: videotrimmer-secrets
                  key: redis-url
          readinessProbe:
            exec:
              command: ["/worker", "-health-check"]
            initialDelaySeconds: 5
            periodSeconds: 10
          volumeMounts:
            - name: tmp-storage
              mountPath: /tmp/transcoder
      volumes:
        - name: tmp-storage
          persistentVolumeClaim:
            claimName: transcoder-tmp-pvc
```

### `go-worker-hpa.yaml`

```yaml
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata:
  name: go-worker-hpa
  namespace: videotrimmer
spec:
  scaleTargetRef:
    apiVersion: apps/v1
    kind: Deployment
    name: go-worker
  minReplicas: 1
  maxReplicas: 10
  metrics:
    - type: Resource
      resource:
        name: cpu
        target:
          type: Utilization
          averageUtilization: 80
  behavior:
    scaleUp:
      stabilizationWindowSeconds: 30
    scaleDown:
      stabilizationWindowSeconds: 300
```

**Go 1.25 + HPA tip:** Go 1.25 reads the pod's cgroup CPU bandwidth limit and sets `GOMAXPROCS` automatically. When K8s limits a pod to 2 CPUs, Go uses exactly 2 OS threads — no over-subscription, no need for `uber-go/automaxprocs`.

---

## Step 2.3 — Observability

**Status: NOT STARTED**

### Prometheus metrics in Go worker

```go
var (
    jobsProcessed = prometheus.NewCounterVec(
        prometheus.CounterOpts{Name: "worker_jobs_total"},
        []string{"status"},
    )
    jobDuration = prometheus.NewHistogram(
        prometheus.HistogramOpts{
            Name:    "worker_job_duration_seconds",
            Buckets: []float64{5, 15, 30, 60, 120, 300},
        },
    )
    activeJobs = prometheus.NewGauge(
        prometheus.GaugeOpts{Name: "worker_active_jobs"},
    )
)
```

### Structured logging with `log/slog`

```go
slog.Info("job started",
    "job_uuid", job.UUID,
    "video_id", job.VideoID,
    "operations", len(job.Operations),
    "worker_id", cfg.WorkerID,
)
```

---

## Step 2.4 — K8s CronJobs

**Status: NOT STARTED**

### `k8s/jobs/db-backup-cronjob.yaml`

```yaml
apiVersion: batch/v1
kind: CronJob
metadata:
  name: db-backup
spec:
  schedule: "0 3 * * *"
  jobTemplate:
    spec:
      template:
        spec:
          containers:
            - name: pg-dump
              image: postgres:17
              command:
                - /bin/sh
                - -c
                - pg_dump $DATABASE_URL | gzip | aws s3 cp - s3://backups/db-$(date +%Y%m%d).sql.gz
          restartPolicy: OnFailure
```

---

## Deployment Options

| Option | Provider | Cost | K8s | Best For |
|---|---|---|---|---|
| **Managed K8s (recommended)** | DigitalOcean DOKS + Spaces | ~$30/mo | Managed | Portfolio demo with real K8s + S3-compatible storage |
| Managed K8s | GKE Autopilot | ~$50/mo | Managed | Enterprise skill signal; best autoscaling |
| Managed K8s | EKS (AWS) | ~$70/mo | Managed | Most common in job listings |
| Self-managed K8s | Hetzner VPS + k3s | ~$15/mo | Self | Cheapest; teaches more ops |
| PaaS | Fly.io | ~$20/mo | No | Fast deploy; `fly scale count` replaces HPA |
| PaaS | Railway | ~$15/mo | No | Easiest DX |

**Recommendation:** DigitalOcean DOKS + DigitalOcean Spaces. Cheapest managed K8s, Spaces is S3-compatible so no code changes from MinIO.

# Step 1.0 — Docker Compose Setup

**Goal:** Get all services running locally before writing any business logic.

## Tasks

- [ ] Create `docker-compose.yml` with services: `nginx`, `laravel`, `postgres`, `redis`, `minio`, `minio-setup`, `go-worker`, `mailpit`
- [ ] Create `docker-compose.override.yml` for dev-only: volume mounts for hot reload, Mailpit, debug ports
- [ ] Write `Makefile` with targets: `make up`, `make down`, `make logs`, `make test-laravel`, `make test-go`, `make migrate`, `make fresh`
- [ ] Verify all containers start and can reach each other: `docker compose up -d && docker compose ps`

## `docker-compose.yml`

```yaml
services:
  nginx:
    image: nginx:alpine
    ports: ["8080:80"]
    volumes:
      - ./laravel-app:/var/www/html
      - ./nginx/default.conf:/etc/nginx/conf.d/default.conf
    depends_on: [laravel]

  laravel:
    build: ./laravel-app
    volumes:
      - ./laravel-app:/var/www/html   # hot reload in dev
    environment:
      - APP_ENV=local
    depends_on: [postgres, redis, minio]

  postgres:
    image: postgres:17
    environment:
      POSTGRES_DB: videotrimmer
      POSTGRES_USER: dev
      POSTGRES_PASSWORD: secret
    volumes:
      - pgdata:/var/lib/postgresql/data
    ports: ["5432:5432"]

  redis:
    image: redis:7-alpine
    ports: ["6379:6379"]

  minio:
    image: minio/minio:latest
    command: server /data --console-address ":9001"
    environment:
      MINIO_ROOT_USER: minioadmin
      MINIO_ROOT_PASSWORD: minioadmin
    ports: ["9000:9000", "9001:9001"]
    volumes:
      - miniodata:/data

  minio-setup:
    image: minio/mc:latest
    depends_on: [minio]
    entrypoint: >
      /bin/sh -c "
      sleep 3;
      mc alias set local http://minio:9000 minioadmin minioadmin;
      mc mb local/videotrimmer-uploads --ignore-existing;
      mc mb local/videotrimmer-outputs --ignore-existing;
      mc anonymous set download local/videotrimmer-outputs;
      exit 0;
      "

  go-worker:
    build: ./go-worker
    environment:
      - REDIS_URL=redis:6379
      - MINIO_ENDPOINT=minio:9000
    depends_on: [redis, minio]

  mailpit:
    image: axllent/mailpit:latest
    ports: ["8025:8025", "1025:1025"]

volumes:
  pgdata:
  miniodata:
```

## Makefile

```makefile
.PHONY: up down logs test-laravel test-go migrate fresh shell-laravel shell-go

up:
	docker compose up -d

down:
	docker compose down

logs:
	docker compose logs -f

migrate:
	docker compose exec laravel php artisan migrate

fresh:
	docker compose exec laravel php artisan migrate:fresh --seed

test-laravel:
	docker compose exec laravel php artisan test --compact --parallel

test-go:
	docker compose exec go-worker go test ./...

test-go-verbose:
	docker compose exec go-worker go test -v -race ./...

shell-laravel:
	docker compose exec laravel sh

shell-go:
	docker compose exec go-worker sh

build:
	docker compose build --no-cache

minio-ui:
	@echo "MinIO console: http://localhost:9001 (minioadmin / minioadmin)"

mail:
	@echo "Mailpit UI: http://localhost:8025"
```

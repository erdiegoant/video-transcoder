.PHONY: up down build logs migrate fresh test shell install artisan

up:
	docker compose up -d

down:
	docker compose down

build:
	docker compose build --no-cache

logs:
	docker compose logs -f

migrate:
	docker compose exec app php artisan migrate

fresh:
	docker compose exec app php artisan migrate:fresh --seed

test:
	docker compose exec app php artisan test --compact

shell:
	docker compose exec app sh

install:
	docker compose exec app composer install

artisan:
	docker compose exec app php artisan $(filter-out $@,$(MAKECMDGOALS))

%:
	@:

UNAME := $(shell uname -s 2>/dev/null || echo Unknown)

ifeq ($(OS),Windows_NT)
compose := docker-compose
else ifneq (,$(filter MINGW% MSYS% CYGWIN%,$(UNAME)))
compose := docker-compose
else
compose := docker compose
endif

compose_dev := $(compose) -f docker-compose.dev.yml
compose_prod := $(compose) -f docker-compose.prod.yml

server := $(compose_dev) exec server
client := $(compose_dev) exec client

docker-init: docker-init-dev

docker-init-dev:
	[ -f server/.env ] || cp server/.env.example server/.env
	[ -f db/.env ] || cp db/.env.example db/.env
	[ -f client/.env ] || cp client/.env.example client/.env
	$(compose_dev) up -d --build

docker-init-prod:
	[ -f server/.env ] || cp server/.env.example server/.env
	[ -f db/.env ] || cp db/.env.example db/.env
	[ -f client/.env ] || cp client/.env.example client/.env
	$(compose_prod) up -d --build
	@sh scripts/wait-prod-client-build.sh "$(compose_prod)" || true
	@$(MAKE) docker-print-prod-urls

docker-up: docker-up-dev

docker-up-dev:
	$(compose_dev) up -d

docker-up-prod:
	$(compose_prod) up -d

docker-down:
	$(compose_dev) down
	-$(compose_prod) down

docker-rebuild: docker-rebuild-dev

docker-rebuild-dev:
	$(compose_dev) up -d --build

docker-rebuild-prod:
	$(compose_prod) up -d --build
	@sh scripts/wait-prod-client-build.sh "$(compose_prod)" || true
	@$(MAKE) docker-print-prod-urls

docker-print-prod-urls:
	@echo ""
	@echo "Production stack is ready."
	@echo "  Client: http://localhost:5173/"
	@echo "  API:    http://localhost:8000/"
	@echo ""

db-migrate:
	$(server) php artisan migrate

db-rebuild:
	$(server) php artisan migrate:fresh

db-migrate-revert:
	$(server) php artisan migrate:rollback --step=1

db-migrate-create:
	$(server) php artisan make:migration $(name) $(args)

db-seeders:
	$(server) php artisan db:seed --force

db-psql:
	$(compose_dev) exec db psql -U rostering -d rostering

db-logs:
	$(compose_dev) logs -f db

server-logs:
	$(compose_dev) logs -f server

server-restart:
	$(compose_dev) restart server

server-app-logs:
	$(compose_dev) exec server tail -f storage/logs/laravel.log

client-logs:
	$(compose_dev) logs -f client

artisan-ide-helper:
	$(server) sh -c "composer require --dev barryvdh/laravel-ide-helper"

artisan-command:
	$(server) php artisan $(args)

test:
	$(server) php artisan test

composer-du:
	$(server) sh -c "composer dump-autoload --quiet --optimize --classmap-authoritative $(args)"

composer-install:
	$(server) sh -c "composer install"

tests:
	$(server) ./vendor/bin/phpunit

optimize-clear-all:
	$(server) php artisan optimize:clear

client-npm-install:
	$(client) npm install

client-build:
	$(client) npm run build

client-lint:
	$(client) npm run lint

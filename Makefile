UNAME := $(shell uname -s 2>/dev/null || echo Unknown)

ifeq ($(OS),Windows_NT)
compose := docker-compose
else ifneq (,$(filter MINGW% MSYS% CYGWIN%,$(UNAME)))
compose := docker-compose
else
compose := docker compose
endif

server := $(compose) exec server
client := $(compose) exec client

docker-init:
	[ -f server/.env ] || cp server/.env.example server/.env
	[ -f db/.env ] || cp db/.env.example db/.env
	[ -f client/.env ] || cp client/.env.example client/.env
	$(compose) up -d --build

docker-up:
	$(compose) up -d

docker-down:
	$(compose) down

docker-rebuild:
	$(compose) up -d --build

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
	$(compose) exec db psql -U rostering -d rostering

db-logs:
	$(compose) logs -f db

server-logs:
	$(compose) logs -f server

client-logs:
	$(compose) logs -f client

artisan-ide-helper:
	$(server) sh -c "composer require --dev barryvdh/laravel-ide-helper"

artisan-command:
	$(server) php artisan $(args)

composer-du:
	$(server) sh -c "composer dump-autoload --quiet --optimize --classmap-authoritative $(args)"

composer-install:
	$(server) sh -c "composer install"

tests:
	$(server) php artisan test

optimize-clear-all:
	$(server) php artisan optimize:clear

client-npm-install:
	$(client) npm install

client-build:
	$(client) npm run build

client-lint:
	$(client) npm run lint

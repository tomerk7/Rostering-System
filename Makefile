UNAME := $(shell uname -s 2>/dev/null || echo Unknown)

ifeq ($(OS),Windows_NT)
compose := docker-compose
else ifneq (,$(filter MINGW% MSYS% CYGWIN%,$(UNAME)))
compose := docker-compose
else
compose := docker compose
endif

server := $(compose) exec server
# Uncomment when the client service is added to docker-compose.yml:
# client := $(compose) exec client

docker-init:
	[ -f server/.env ] || cp server/.env.example server/.env
	$(compose) up -d

docker-up:
	$(compose) up -d

docker-down:
	$(compose) down

docker-rebuild:
	$(compose) up -d --build

db-migrate:
	$(server) php artisan migrate

db-migrate-revert:
	$(server) php artisan migrate:rollback --step=1

db-migrate-create:
	$(server) php artisan make:migration $(name) $(args)

db-seeders:
	$(server) php artisan db:seed --force

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

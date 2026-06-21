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

server_vanilla := $(compose_dev) exec server-vanilla
server_vanilla_prod := $(compose_prod) exec server-vanilla
client := $(compose_dev) exec client

docker-init: docker-init-dev

docker-init-dev:
	[ -f server-vanilla/.env ] || cp server-vanilla/.env.example server-vanilla/.env
	[ -f db/.env ] || cp db/.env.example db/.env
	[ -f client/.env ] || cp client/.env.example client/.env
	$(compose_dev) up -d --build

docker-init-prod:
	[ -f server-vanilla/.env ] || cp server-vanilla/.env.example server-vanilla/.env
	[ -f db/.env ] || cp db/.env.example db/.env
	[ -f client/.env ] || cp client/.env.example client/.env
	@echo "Tearing down any existing prod stack..."
	-$(compose_prod) down --remove-orphans
	$(compose_prod) up -d --build
	@echo "Resetting database (drop schema + fresh migrate + seed)..."
	$(server_vanilla_prod) sh -c 'php bin/migrate.php --fresh && php bin/seed.php'
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

# Schema + seed are owned by the vanilla app (server-vanilla/bin/*.php), not artisan.
db-migrate:
	$(server_vanilla) php bin/migrate.php

db-rebuild:
	$(server_vanilla) sh -c 'php bin/migrate.php --fresh && php bin/seed.php'

# Prod boots schema on container start (idempotent); this forces a fresh rebuild.
db-rebuild-prod:
	$(server_vanilla_prod) sh -c 'php bin/migrate.php --fresh && php bin/seed.php'

db-migrate-revert:
	@echo "Rollback is not supported: vanilla migrations are forward-only."
	@echo "To reset the dev DB use: make db-rebuild"

db-migrate-create:
	@echo "Add a new SQL file: server-vanilla/database/migrations/<NNNN>_<name>.sql"
	@echo "(numeric prefix after the highest existing one; it runs in filename order)"

db-seeders:
	$(server_vanilla) php bin/seed.php

# Seed dev/test workers for a staffing profile (raw PDO; dev fixtures only).
# e.g. make seed-workers args="optimization --coverage-factor=6 --fresh"
seed-workers:
	$(server_vanilla) php bin/seed-workers.php $(args)

db-psql:
	$(compose_dev) exec db psql -U rostering -d rostering

db-logs:
	$(compose_dev) logs -f db

vanilla-logs:
	$(compose_dev) logs -f server-vanilla

vanilla-shell:
	$(server_vanilla) sh

vanilla-composer-du:
	$(server_vanilla) sh -c "composer dump-autoload --optimize --classmap-authoritative $(args)"

nginx-logs:
	$(compose_dev) logs -f nginx

nginx-reload:
	$(compose_dev) exec nginx nginx -s reload

client-logs:
	$(compose_dev) logs -f client

client-npm-install:
	$(client) npm install

client-build:
	$(client) npm run build

client-lint:
	$(client) npm run lint

# Run the vanilla backend PHPUnit suite inside the container.
# e.g. make test args="--testsuite unit"
test:
	$(server_vanilla) vendor/bin/phpunit $(args)

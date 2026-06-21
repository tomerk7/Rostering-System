#!/usr/bin/env sh
set -e

# Generate the Composer PSR-4 autoloader on boot (no third-party deps yet, so
# this just builds vendor/autoload.php used by the CLI tools and the app).
composer install --no-interaction --no-progress

# The vanilla app owns the database schema and reference data. Run migrations
# then the idempotent seed on every boot (both are safe to re-run), then signal
# readiness so the the app service can start against a prepared database.
php /var/www/bin/migrate.php
php /var/www/bin/seed.php

# Healthcheck marker: "healthy" => schema + seed are done.
touch /tmp/vanilla-ready

exec php-fpm


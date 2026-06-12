#!/usr/bin/env sh
set -e

composer install --no-interaction --no-progress --no-dev --optimize-autoloader

if [ -f .env ]; then
	APP_KEY_LINE=$(grep '^APP_KEY=' .env || true)
	APP_KEY_VALUE=$(printf '%s' "$APP_KEY_LINE" | cut -d= -f2-)
	if [ -z "$APP_KEY_VALUE" ]; then
		php artisan key:generate --force
	fi
fi

php artisan migrate --force

php artisan db:seed --force

php artisan config:cache
php artisan route:cache
php artisan view:cache

(
	while true; do
		php artisan queue:work --queue=default --sleep=1 --tries=1 --timeout=1800 --memory=512 --max-time=3600
		echo "[docker-start.prod] queue worker exited; restarting in 2s" >&2
		sleep 2
	done
) &

php artisan serve --host=0.0.0.0 --port=8000

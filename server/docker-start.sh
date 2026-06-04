#!/usr/bin/env sh
set -e

cd /var/www

# Always make sure composer is installed on boot.
# This is to make the first run smoother so no need to run composer install manually.
composer install --no-interaction --no-progress

chmod -R ug+rwx storage bootstrap/cache 2>/dev/null || true

if grep -qE '^DB_CONNECTION=sqlite' .env 2>/dev/null; then
	if [ ! -f database/database.sqlite ]; then
		touch database/database.sqlite
	fi
fi

# Ensure APP_KEY is set before running framework commands that rely on encryption.
if [ -f .env ]; then
	APP_KEY_LINE=$(grep '^APP_KEY=' .env || true)
	APP_KEY_VALUE=$(printf '%s' "$APP_KEY_LINE" | cut -d= -f2-)
	if [ -z "$APP_KEY_VALUE" ]; then
		php artisan key:generate --force
	fi
fi

php artisan migrate --force
php artisan db:seed --force

# Start cron so the Laravel scheduler (configured in /etc/cron.d/laravel-scheduler) runs.
service cron start

# Process queued jobs using the database queue connection.
php artisan queue:work --queue=default --sleep=1 --tries=1 --max-time=0 &

php artisan serve --host=0.0.0.0 --port=8000

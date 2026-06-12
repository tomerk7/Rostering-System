#!/usr/bin/env sh
set -e

# Always make sure composer is installed on boot.
# This is to make the first run smoother so no need to run composer install manually.
composer install --no-interaction --no-progress

# Ensure APP_KEY is set before running framework commands that rely on encryption.
if [ -f .env ]; then
	APP_KEY_LINE=$(grep '^APP_KEY=' .env || true)
	APP_KEY_VALUE=$(printf '%s' "$APP_KEY_LINE" | cut -d= -f2-)
	if [ -z "$APP_KEY_VALUE" ]; then
		php artisan key:generate --force
	fi
fi

php artisan migrate --force;

# Process queued jobs using the database queue connection.
#
# Wrapped in a restart loop so a worker that exits (timeout, OOM, fatal error,
# or the hourly --max-time recycle) is brought back automatically instead of
# leaving jobs stuck in the queue. --timeout must be >= the job's own timeout,
# and --memory gives heavy imports headroom above the 128MB default.
(
	while true; do
		php artisan queue:work --queue=default --sleep=1 --tries=1 --timeout=1800 --memory=512 --max-time=3600
		echo "[docker-start] queue worker exited; restarting in 2s" >&2
		sleep 2
	done
) &

php artisan serve --host=0.0.0.0 --port=8000

#!/usr/bin/env sh
set -e

COMPOSE_CMD="${1:-docker compose -f docker-compose.prod.yml}"
MAX_ATTEMPTS="${MAX_ATTEMPTS:-120}"
SLEEP_SECONDS="${SLEEP_SECONDS:-2}"

echo "Waiting for production server to finish booting..."

attempt=0
while [ "$attempt" -lt "$MAX_ATTEMPTS" ]; do
	if $COMPOSE_CMD logs server 2>&1 | grep -q "Server running on"; then
		exit 0
	fi

	attempt=$((attempt + 1))
	sleep "$SLEEP_SECONDS"
done

echo "Timed out waiting for server. Check logs with: $COMPOSE_CMD logs -f server" >&2
exit 1

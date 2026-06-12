#!/usr/bin/env sh
set -e

COMPOSE_CMD="${1:-docker compose -f docker-compose.prod.yml}"
MAX_ATTEMPTS="${MAX_ATTEMPTS:-60}"
SLEEP_SECONDS="${SLEEP_SECONDS:-5}"

echo "Waiting for client production build to finish..."

attempt=0
while [ "$attempt" -lt "$MAX_ATTEMPTS" ]; do
	if $COMPOSE_CMD logs client 2>&1 | grep -q "Client build complete."; then
		exit 0
	fi

	attempt=$((attempt + 1))
	sleep "$SLEEP_SECONDS"
done

echo "Timed out waiting for client build. Check logs with: $COMPOSE_CMD logs -f client" >&2
exit 1

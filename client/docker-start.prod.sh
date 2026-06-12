#!/usr/bin/env sh
set -e

npm ci

npm run build

echo ""
echo "Client build complete."
echo "  Client: http://localhost:5173/"
echo "  API:    http://localhost:8000/"
echo ""

npm run preview -- --host 0.0.0.0 --port 5173

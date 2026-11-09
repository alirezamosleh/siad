#!/usr/bin/env bash
# Apply Hoda UI / mounts and restart Greenlight
set -euo pipefail
cd "$(dirname "$0")"

DOCKER_CMD="docker"
if ! docker info >/dev/null 2>&1; then
  echo "Notice: sudo password may be required for docker."
  DOCKER_CMD="sudo docker"
fi

# Prefer Compose V2 plugin; fall back to docker-compose V1
if $DOCKER_CMD compose version >/dev/null 2>&1; then
  COMPOSE=("$DOCKER_CMD" compose)
elif command -v docker-compose >/dev/null 2>&1; then
  if [[ "$DOCKER_CMD" == "sudo docker" ]]; then
    COMPOSE=(sudo docker-compose)
  else
    COMPOSE=(docker-compose)
  fi
else
  echo "ERROR: neither 'docker compose' nor 'docker-compose' found"
  exit 1
fi

echo "==> Recreating greenlight-v3 (new mounts need recreate, not just restart)..."
# Avoid flaky V1 recreate plan: remove app container then up
$DOCKER_CMD rm -f gl-direct-app >/dev/null 2>&1 || true
"${COMPOSE[@]}" up -d --no-deps greenlight-v3

echo "==> Waiting for application..."
for i in {1..40}; do
  if curl -sf -o /dev/null http://localhost:3000/; then
    echo "==> Application is up!"
    break
  fi
  sleep 1
done

echo "==> Verifying assets..."
curl -sI http://localhost:3000/hoda-theme.css | head -n 2
curl -sI http://localhost:3000/styles/hoda-main.js | head -n 2

echo ""
echo "Open http://localhost:3000 and hard-refresh (Ctrl+Shift+R)."

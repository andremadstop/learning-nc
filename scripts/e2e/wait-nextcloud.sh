#!/usr/bin/env bash
set -euo pipefail

APP_CONTAINER="${APP_CONTAINER:-learning-app}"
MAX_WAIT_SECONDS="${MAX_WAIT_SECONDS:-300}"
SLEEP_SECONDS="${SLEEP_SECONDS:-5}"

echo "[e2e] waiting for Nextcloud container '${APP_CONTAINER}' to become ready..."
start_ts="$(date +%s)"

while true; do
  if docker exec "${APP_CONTAINER}" php occ status --output=json 2>/dev/null | grep -q '"installed":true'; then
    echo "[e2e] Nextcloud is installed and reachable."
    break
  fi

  now_ts="$(date +%s)"
  elapsed="$((now_ts - start_ts))"
  if [ "${elapsed}" -ge "${MAX_WAIT_SECONDS}" ]; then
    echo "[e2e] timeout after ${MAX_WAIT_SECONDS}s waiting for Nextcloud readiness"
    exit 1
  fi
  sleep "${SLEEP_SECONDS}"
done


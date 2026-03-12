#!/usr/bin/env bash
set -euo pipefail

APP_CONTAINER="${APP_CONTAINER:-learning-app}"
MAX_WAIT_SECONDS="${MAX_WAIT_SECONDS:-900}"
SLEEP_SECONDS="${SLEEP_SECONDS:-5}"

echo "[e2e] waiting for Nextcloud container '${APP_CONTAINER}' to become ready..."
start_ts="$(date +%s)"

while true; do
  status_json="$(docker exec "${APP_CONTAINER}" php occ status --output=json 2>/dev/null || true)"
  if printf '%s' "${status_json}" | grep -q '"installed":true'; then
    echo "[e2e] Nextcloud is installed and reachable."
    break
  fi

  if [ -n "${status_json}" ]; then
    echo "[e2e] waiting... occ status: ${status_json}"
  else
    echo "[e2e] waiting... container not ready for occ yet"
  fi

  now_ts="$(date +%s)"
  elapsed="$((now_ts - start_ts))"
  if [ "${elapsed}" -ge "${MAX_WAIT_SECONDS}" ]; then
    echo "[e2e] timeout after ${MAX_WAIT_SECONDS}s waiting for Nextcloud readiness"
    exit 1
  fi
  sleep "${SLEEP_SECONDS}"
done

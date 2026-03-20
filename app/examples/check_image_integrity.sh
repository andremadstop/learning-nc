#!/usr/bin/env bash
set -euo pipefail

# Run inside the Nextcloud app/container host context where DB access exists.
# This script is intentionally read-only and prints potential integrity issues.

DB_CONTAINER="${DB_CONTAINER:-learning-db}"
APP_CONTAINER="${APP_CONTAINER:-learning-app}"
APP_DB_USER="${APP_DB_USER:-nextcloud}"
APP_DB_NAME="${APP_DB_NAME:-nextcloud}"

echo "[1/3] Counting questions with image_path..."
docker exec -i "$DB_CONTAINER" psql -U "$APP_DB_USER" -d "$APP_DB_NAME" -Atqc \
  "SELECT COUNT(*) FROM oc_learning_questions WHERE image_path IS NOT NULL AND image_path <> '';" \
  | awk '{print "with_image_path=" $1}'

echo "[2/3] Listing broken image paths (first 50)..."
docker exec -i "$DB_CONTAINER" psql -U "$APP_DB_USER" -d "$APP_DB_NAME" -AtF $'\t' -c \
  "SELECT id, image_path FROM oc_learning_questions WHERE image_path IS NOT NULL AND image_path <> '' ORDER BY id;" \
  | while IFS=$'\t' read -r qid path; do
      fs_path="/var/www/html/data/${path/admin\//admin/files/}"
      if ! docker exec "$APP_CONTAINER" sh -lc "[ -f '$fs_path' ]" >/dev/null 2>&1; then
        printf "%s\t%s\n" "$qid" "$path"
      fi
    done | head -n 50

echo "[3/3] Done. Use occ files:scan for stale file cache if needed."

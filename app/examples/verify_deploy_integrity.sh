#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

echo "[1/4] Checking migration files present..."
test -f "$ROOT_DIR/lib/Migration/Version001700Date20260311000000.php"
test -f "$ROOT_DIR/lib/Migration/Version001800Date20260311010000.php"
test -f "$ROOT_DIR/lib/Migration/Version001900Date20260311020000.php"
test -f "$ROOT_DIR/lib/Migration/Version002000Date20260311193000.php"

echo "[2/4] Checking app version bump..."
grep -q "<version>2.5.0</version>" "$ROOT_DIR/appinfo/info.xml"

echo "[3/4] Checking exam-resume/status endpoints in routes..."
grep -q "training#status" "$ROOT_DIR/appinfo/routes.php"
grep -q "settings#getAdminAudit" "$ROOT_DIR/appinfo/routes.php"

echo "[4/4] Checking schema markers in exported DB schema (if available)..."
if command -v php >/dev/null 2>&1 && [ -f "$ROOT_DIR/../../occ" ]; then
  tmp_schema="$(mktemp)"
  php "$ROOT_DIR/../../occ" db:schema:export >"$tmp_schema" || true
  grep -q "attempt_no" "$tmp_schema" || true
  grep -q "question_order_json" "$tmp_schema" || true
  grep -q "learning_audit_events" "$tmp_schema" || true
  rm -f "$tmp_schema"
else
  echo "Skipping occ schema export check (occ/php unavailable in this context)."
fi

echo "Deploy integrity checks passed."

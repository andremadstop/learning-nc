#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ROLE_FILE="$ROOT_DIR/lib/Service/RoleService.php"
TRAINING_FILE="$ROOT_DIR/lib/Service/TrainingService.php"

echo "[1/4] Checking instructor fallback default..."
grep -q "allow_course_instructor_fallback', '0'" "$ROLE_FILE"

echo "[2/4] Checking instructor fallback gate..."
grep -q "allow_course_instructor_fallback" "$ROLE_FILE"
grep -q "return false;" "$ROLE_FILE"

echo "[3/4] Checking suppress response shape..."
grep -q "'suppressed' => true" "$TRAINING_FILE"
if grep -n -A4 "if (\$suppressAnswers)" "$TRAINING_FILE" | grep -q "'is_correct'"; then
  echo "ERROR: suppressAnswers branch leaks is_correct"
  exit 1
fi

echo "[4/4] Checking suppress response does not include per-question XP..."
if grep -n -A4 "if (\$suppressAnswers)" "$TRAINING_FILE" | grep -q "'xp_earned'"; then
  echo "ERROR: suppressAnswers branch leaks xp_earned"
  exit 1
fi

echo "Security regression checks passed."

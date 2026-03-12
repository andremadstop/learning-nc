#!/usr/bin/env bash
set -euo pipefail

APP_CONTAINER="${APP_CONTAINER:-learning-app}"
DB_CONTAINER="${DB_CONTAINER:-learning-db}"
NC_DB_USER="${NC_DB_USER:-nextcloud}"
NC_DB_NAME="${NC_DB_NAME:-nextcloud}"
E2E_USER="${E2E_USER:-admin}"
E2E_PASSWORD="${E2E_PASSWORD:-admin}"
E2E_BASE_URL="${E2E_BASE_URL:-http://localhost:8080/apps/learning}"
OUT_ENV_FILE="${OUT_ENV_FILE:-app/tests/e2e/.env.generated}"

POOL_NAME="E2E Fixture Pool"
QUESTION_TEXT="E2E: What is 2+2?"

echo "[e2e] enabling learning app and running migrations"
docker exec "${APP_CONTAINER}" php occ app:enable learning >/dev/null 2>&1 || true
docker exec "${APP_CONTAINER}" php occ upgrade >/dev/null 2>&1 || true

TABLE_PREFIX="${NC_TABLE_PREFIX:-}"
if [ -z "${TABLE_PREFIX}" ]; then
  TABLE_PREFIX="$(docker exec "${APP_CONTAINER}" php occ config:system:get dbtableprefix 2>/dev/null | tr -d '\r\n' || true)"
fi
if [ -z "${TABLE_PREFIX}" ]; then
  TABLE_PREFIX="oc_"
fi
if [ -z "${TABLE_PREFIX}" ]; then
  echo "[e2e] failed to detect db table prefix"
  exit 1
fi

NOW="$(date +%s)"
STARTED_RECENT="$((NOW - 30))"
STARTED_TIMEOUT="$((NOW - 7200))"
STARTED_TRAINING="$((NOW - 120))"
COMPLETED_RECENT="$((NOW - 20))"

echo "[e2e] cleaning old fixture data"
docker exec "${DB_CONTAINER}" psql -U "${NC_DB_USER}" -d "${NC_DB_NAME}" -v ON_ERROR_STOP=1 -c "
DELETE FROM ${TABLE_PREFIX}learning_user_answers
WHERE session_id IN (
  SELECT s.id
  FROM ${TABLE_PREFIX}learning_sessions s
  JOIN ${TABLE_PREFIX}learning_pools p ON p.id = s.pool_id
  WHERE p.user_id = '${E2E_USER}' AND p.name = '${POOL_NAME}'
);

DELETE FROM ${TABLE_PREFIX}learning_sessions
WHERE pool_id IN (
  SELECT id FROM ${TABLE_PREFIX}learning_pools
  WHERE user_id = '${E2E_USER}' AND name = '${POOL_NAME}'
);

DELETE FROM ${TABLE_PREFIX}learning_answers
WHERE question_id IN (
  SELECT q.id FROM ${TABLE_PREFIX}learning_questions q
  JOIN ${TABLE_PREFIX}learning_pools p ON p.id = q.pool_id
  WHERE p.user_id = '${E2E_USER}' AND p.name = '${POOL_NAME}'
);

DELETE FROM ${TABLE_PREFIX}learning_questions
WHERE pool_id IN (
  SELECT id FROM ${TABLE_PREFIX}learning_pools
  WHERE user_id = '${E2E_USER}' AND name = '${POOL_NAME}'
);

DELETE FROM ${TABLE_PREFIX}learning_pools
WHERE user_id = '${E2E_USER}' AND name = '${POOL_NAME}';
"

echo "[e2e] creating fixture pool/question/answers"
POOL_ID="$(docker exec "${DB_CONTAINER}" psql -U "${NC_DB_USER}" -d "${NC_DB_NAME}" -At -v ON_ERROR_STOP=1 -c "
INSERT INTO ${TABLE_PREFIX}learning_pools (user_id, name, description, created_at, updated_at, review_status)
VALUES ('${E2E_USER}', '${POOL_NAME}', 'Seeded fixtures for E2E', ${NOW}, ${NOW}, 'published')
RETURNING id;
")"

QUESTION_ID="$(docker exec "${DB_CONTAINER}" psql -U "${NC_DB_USER}" -d "${NC_DB_NAME}" -At -v ON_ERROR_STOP=1 -c "
INSERT INTO ${TABLE_PREFIX}learning_questions (pool_id, user_id, text, explanation, difficulty, created_at, updated_at, question_type, review_status)
VALUES (${POOL_ID}, '${E2E_USER}', '${QUESTION_TEXT}', 'E2E explanation', 'easy', ${NOW}, ${NOW}, 'single', 'published')
RETURNING id;
")"

CORRECT_ANSWER_ID="$(docker exec "${DB_CONTAINER}" psql -U "${NC_DB_USER}" -d "${NC_DB_NAME}" -At -v ON_ERROR_STOP=1 -c "
INSERT INTO ${TABLE_PREFIX}learning_answers (question_id, text, is_correct, position)
VALUES (${QUESTION_ID}, '4', true, 0)
RETURNING id;
")"

WRONG_ANSWER_ID="$(docker exec "${DB_CONTAINER}" psql -U "${NC_DB_USER}" -d "${NC_DB_NAME}" -At -v ON_ERROR_STOP=1 -c "
INSERT INTO ${TABLE_PREFIX}learning_answers (question_id, text, is_correct, position)
VALUES (${QUESTION_ID}, '5', false, 1)
RETURNING id;
")"

echo "[e2e] creating exam/training fixture sessions"
EXAM_SESSION_ID="$(docker exec "${DB_CONTAINER}" psql -U "${NC_DB_USER}" -d "${NC_DB_NAME}" -At -v ON_ERROR_STOP=1 -c "
INSERT INTO ${TABLE_PREFIX}learning_sessions (pool_id, user_id, started_at, completed_at, total_questions, correct_answers, mode, time_limit_seconds, attempt_no, question_order_json)
VALUES (${POOL_ID}, '${E2E_USER}', ${STARTED_RECENT}, NULL, 1, 0, 'exam', 1800, 1, '[${QUESTION_ID}]')
RETURNING id;
")"

TIMEOUT_SESSION_ID="$(docker exec "${DB_CONTAINER}" psql -U "${NC_DB_USER}" -d "${NC_DB_NAME}" -At -v ON_ERROR_STOP=1 -c "
INSERT INTO ${TABLE_PREFIX}learning_sessions (pool_id, user_id, started_at, completed_at, total_questions, correct_answers, mode, time_limit_seconds, attempt_no, question_order_json)
VALUES (${POOL_ID}, '${E2E_USER}', ${STARTED_TIMEOUT}, NULL, 1, 0, 'exam', 60, 1, '[${QUESTION_ID}]')
RETURNING id;
")"

TRAIN_SESSION_ID="$(docker exec "${DB_CONTAINER}" psql -U "${NC_DB_USER}" -d "${NC_DB_NAME}" -At -v ON_ERROR_STOP=1 -c "
INSERT INTO ${TABLE_PREFIX}learning_sessions (pool_id, user_id, started_at, completed_at, total_questions, correct_answers, mode, time_limit_seconds, question_order_json)
VALUES (${POOL_ID}, '${E2E_USER}', ${STARTED_TRAINING}, NULL, 1, 0, 'training', NULL, '[${QUESTION_ID}]')
RETURNING id;
")"

# completed session to satisfy at least one daily mission claim (daily_sessions_1)
docker exec "${DB_CONTAINER}" psql -U "${NC_DB_USER}" -d "${NC_DB_NAME}" -v ON_ERROR_STOP=1 -c "
INSERT INTO ${TABLE_PREFIX}learning_sessions (pool_id, user_id, started_at, completed_at, total_questions, correct_answers, mode, time_limit_seconds, question_order_json)
VALUES (${POOL_ID}, '${E2E_USER}', ${STARTED_TRAINING}, ${COMPLETED_RECENT}, 10, 8, 'training', NULL, '[${QUESTION_ID}]');
"

mkdir -p "$(dirname "${OUT_ENV_FILE}")"
cat > "${OUT_ENV_FILE}" <<EOF
E2E_AUTH_READY=1
E2E_BASE_URL=${E2E_BASE_URL}
E2E_USERNAME=${E2E_USER}
E2E_PASSWORD=${E2E_PASSWORD}
E2E_EXAM_SESSION_ID=${EXAM_SESSION_ID}
E2E_EXAM_QUESTION_ID=${QUESTION_ID}
E2E_EXAM_ANSWER_ID=${WRONG_ANSWER_ID}
E2E_TIMEOUT_SESSION_ID=${TIMEOUT_SESSION_ID}
E2E_TRAIN_SESSION_ID=${TRAIN_SESSION_ID}
E2E_TRAIN_QUESTION_ID=${QUESTION_ID}
E2E_TRAIN_ANSWER_ID=${CORRECT_ANSWER_ID}
E2E_MISSION_KEY=daily_sessions_1
E2E_UNCOMPLETED_MISSION_KEY=daily_cards_20
EOF

echo "[e2e] fixture env written to ${OUT_ENV_FILE}"
echo "[e2e] pool=${POOL_ID} question=${QUESTION_ID} examSession=${EXAM_SESSION_ID}"

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
COURSE_TITLE="E2E Fixture Course"
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

DELETE FROM ${TABLE_PREFIX}learning_course_members
WHERE course_id IN (
  SELECT id FROM ${TABLE_PREFIX}learning_courses
  WHERE instructor_id = '${E2E_USER}' AND title = '${COURSE_TITLE}'
);

DELETE FROM ${TABLE_PREFIX}learning_course_pools
WHERE course_id IN (
  SELECT id FROM ${TABLE_PREFIX}learning_courses
  WHERE instructor_id = '${E2E_USER}' AND title = '${COURSE_TITLE}'
);

DELETE FROM ${TABLE_PREFIX}learning_courses
WHERE instructor_id = '${E2E_USER}' AND title = '${COURSE_TITLE}';
"

echo "[e2e] creating fixture pool/question/answers"
# psql sometimes emits a command tag ("INSERT 0 1") before the RETURNING value even with -At.
# Pipe through grep to extract only the numeric ID line.
POOL_ID="$(docker exec "${DB_CONTAINER}" psql -U "${NC_DB_USER}" -d "${NC_DB_NAME}" -At -v ON_ERROR_STOP=1 -c "
INSERT INTO ${TABLE_PREFIX}learning_pools (user_id, name, description, created_at, updated_at, review_status)
VALUES ('${E2E_USER}', '${POOL_NAME}', 'Seeded fixtures for E2E', ${NOW}, ${NOW}, 'published')
RETURNING id;
" | grep -E '^[0-9]+$')"

QUESTION_ID="$(docker exec "${DB_CONTAINER}" psql -U "${NC_DB_USER}" -d "${NC_DB_NAME}" -At -v ON_ERROR_STOP=1 -c "
INSERT INTO ${TABLE_PREFIX}learning_questions (pool_id, user_id, text, explanation, difficulty, created_at, updated_at, question_type, review_status)
VALUES (${POOL_ID}, '${E2E_USER}', '${QUESTION_TEXT}', 'E2E explanation', 'easy', ${NOW}, ${NOW}, 'single', 'published')
RETURNING id;
" | grep -E '^[0-9]+$')"

CORRECT_ANSWER_ID="$(docker exec "${DB_CONTAINER}" psql -U "${NC_DB_USER}" -d "${NC_DB_NAME}" -At -v ON_ERROR_STOP=1 -c "
INSERT INTO ${TABLE_PREFIX}learning_answers (question_id, text, is_correct, position)
VALUES (${QUESTION_ID}, '4', true, 0)
RETURNING id;
" | grep -E '^[0-9]+$')"

WRONG_ANSWER_ID="$(docker exec "${DB_CONTAINER}" psql -U "${NC_DB_USER}" -d "${NC_DB_NAME}" -At -v ON_ERROR_STOP=1 -c "
INSERT INTO ${TABLE_PREFIX}learning_answers (question_id, text, is_correct, position)
VALUES (${QUESTION_ID}, '5', false, 1)
RETURNING id;
" | grep -E '^[0-9]+$')"

echo "[e2e] creating fixture course and assigning seeded pool"
COURSE_ID="$(docker exec "${DB_CONTAINER}" psql -U "${NC_DB_USER}" -d "${NC_DB_NAME}" -At -v ON_ERROR_STOP=1 -c "
INSERT INTO ${TABLE_PREFIX}learning_courses (title, description, instructor_id, status, created_at, updated_at)
VALUES ('${COURSE_TITLE}', 'Seeded course for E2E flows', '${E2E_USER}', 'active', ${NOW}, ${NOW})
RETURNING id;
" | grep -E '^[0-9]+$')"

docker exec "${DB_CONTAINER}" psql -U "${NC_DB_USER}" -d "${NC_DB_NAME}" -v ON_ERROR_STOP=1 -c "
INSERT INTO ${TABLE_PREFIX}learning_course_pools (course_id, pool_id, sort_order, required)
VALUES (${COURSE_ID}, ${POOL_ID}, 0, 1);
"

echo "[e2e] creating exam/training fixture sessions"
EXAM_SESSION_ID="$(docker exec "${DB_CONTAINER}" psql -U "${NC_DB_USER}" -d "${NC_DB_NAME}" -At -v ON_ERROR_STOP=1 -c "
INSERT INTO ${TABLE_PREFIX}learning_sessions (pool_id, user_id, started_at, completed_at, total_questions, correct_answers, mode, time_limit_seconds, attempt_no, question_order_json)
VALUES (${POOL_ID}, '${E2E_USER}', ${STARTED_RECENT}, NULL, 1, 0, 'exam', 1800, 1, '[${QUESTION_ID}]')
RETURNING id;
" | grep -E '^[0-9]+$')"

TIMEOUT_SESSION_ID="$(docker exec "${DB_CONTAINER}" psql -U "${NC_DB_USER}" -d "${NC_DB_NAME}" -At -v ON_ERROR_STOP=1 -c "
INSERT INTO ${TABLE_PREFIX}learning_sessions (pool_id, user_id, started_at, completed_at, total_questions, correct_answers, mode, time_limit_seconds, attempt_no, question_order_json)
VALUES (${POOL_ID}, '${E2E_USER}', ${STARTED_TIMEOUT}, NULL, 1, 0, 'exam', 60, 1, '[${QUESTION_ID}]')
RETURNING id;
" | grep -E '^[0-9]+$')"

TRAIN_SESSION_ID="$(docker exec "${DB_CONTAINER}" psql -U "${NC_DB_USER}" -d "${NC_DB_NAME}" -At -v ON_ERROR_STOP=1 -c "
INSERT INTO ${TABLE_PREFIX}learning_sessions (pool_id, user_id, started_at, completed_at, total_questions, correct_answers, mode, time_limit_seconds, question_order_json)
VALUES (${POOL_ID}, '${E2E_USER}', ${STARTED_TRAINING}, NULL, 1, 0, 'training', NULL, '[${QUESTION_ID}]')
RETURNING id;
" | grep -E '^[0-9]+$')"

# separate training session for anti-oracle suppression test (isolates from duplicate-submission test)
SUPPRESS_SESSION_ID="$(docker exec "${DB_CONTAINER}" psql -U "${NC_DB_USER}" -d "${NC_DB_NAME}" -At -v ON_ERROR_STOP=1 -c "
INSERT INTO ${TABLE_PREFIX}learning_sessions (pool_id, user_id, started_at, completed_at, total_questions, correct_answers, mode, time_limit_seconds, question_order_json)
VALUES (${POOL_ID}, '${E2E_USER}', ${STARTED_TRAINING}, NULL, 1, 0, 'training', NULL, '[${QUESTION_ID}]')
RETURNING id;
" | grep -E '^[0-9]+$')"

# completed session to satisfy at least one daily mission claim (daily_sessions_1)
docker exec "${DB_CONTAINER}" psql -U "${NC_DB_USER}" -d "${NC_DB_NAME}" -v ON_ERROR_STOP=1 -c "
INSERT INTO ${TABLE_PREFIX}learning_sessions (pool_id, user_id, started_at, completed_at, total_questions, correct_answers, mode, time_limit_seconds, question_order_json)
VALUES (${POOL_ID}, '${E2E_USER}', ${STARTED_TRAINING}, ${COMPLETED_RECENT}, 10, 8, 'training', NULL, '[${QUESTION_ID}]');
"

printf -v COURSE_TITLE_ENV '%q' "${COURSE_TITLE}"
printf -v POOL_NAME_ENV '%q' "${POOL_NAME}"

mkdir -p "$(dirname "${OUT_ENV_FILE}")"
cat > "${OUT_ENV_FILE}" <<EOF
E2E_AUTH_READY=1
E2E_BASE_URL=${E2E_BASE_URL}
E2E_USERNAME=${E2E_USER}
E2E_PASSWORD=${E2E_PASSWORD}
E2E_COURSE_ID=${COURSE_ID}
E2E_COURSE_TITLE=${COURSE_TITLE_ENV}
E2E_POOL_ID=${POOL_ID}
E2E_POOL_NAME=${POOL_NAME_ENV}
E2E_EXAM_SESSION_ID=${EXAM_SESSION_ID}
E2E_EXAM_QUESTION_ID=${QUESTION_ID}
E2E_EXAM_ANSWER_ID=${WRONG_ANSWER_ID}
E2E_TIMEOUT_SESSION_ID=${TIMEOUT_SESSION_ID}
E2E_TRAIN_SESSION_ID=${TRAIN_SESSION_ID}
E2E_TRAIN_QUESTION_ID=${QUESTION_ID}
E2E_TRAIN_ANSWER_ID=${CORRECT_ANSWER_ID}
E2E_SUPPRESS_SESSION_ID=${SUPPRESS_SESSION_ID}
E2E_MISSION_KEY=daily_sessions_1
E2E_UNCOMPLETED_MISSION_KEY=daily_cards_20
EOF

echo "[e2e] fixture env written to ${OUT_ENV_FILE}"
echo "[e2e] course=${COURSE_ID} pool=${POOL_ID} question=${QUESTION_ID} examSession=${EXAM_SESSION_ID}"

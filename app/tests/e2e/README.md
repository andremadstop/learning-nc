# E2E Test Setup

This folder contains Playwright E2E tests for critical app flows.

## Local Run (Docker Compose)

From repo root:

```bash
docker compose up -d
bash scripts/e2e/wait-nextcloud.sh
npm --prefix app ci
npm --prefix app run build
npx --prefix app playwright install chromium
bash scripts/e2e/seed-fixtures.sh
set -a; source app/tests/e2e/.env.generated; set +a
npm --prefix app run test:e2e:ci
```

## Seeded Fixtures

`scripts/e2e/seed-fixtures.sh` creates:

- one pool owned by `admin`
- one question with one correct and one wrong answer
- one active exam session (anti-oracle test)
- one expired exam session (timeout test)
- one active training session (duplicate answer idempotency test)
- one completed session for mission claim tests

Fixture IDs are written to:

`app/tests/e2e/.env.generated`

## Environment Variables

Common vars:

- `E2E_BASE_URL` (default: `http://localhost:8080/apps/learning`)
- `E2E_USERNAME` (default: `admin`)
- `E2E_PASSWORD` (default: `admin`)
- `APP_CONTAINER` (default: `learning-app`)
- `DB_CONTAINER` (default: `learning-db`)


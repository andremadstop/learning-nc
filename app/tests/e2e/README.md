# E2E Test Setup

This folder contains Playwright E2E tests for critical app flows.

## Local Run (Docker Compose)

From repo root:

```bash
npm --prefix app ci
npm --prefix app run build
docker compose -f docker-compose.e2e.yml up -d
bash scripts/e2e/wait-nextcloud.sh
docker exec learning-app sh -lc 'mkdir -p /var/www/html/custom_apps/learning'
tar --exclude=node_modules -C app -cf - . | docker exec -i learning-app tar -xf - -C /var/www/html/custom_apps/learning
docker exec learning-app sh -lc 'chown -R www-data:www-data /var/www/html/custom_apps/learning'
docker exec learning-app php occ app:enable learning
npx --prefix app playwright install chromium
bash scripts/e2e/seed-fixtures.sh
set -a; source app/tests/e2e/.env.generated; set +a
npm --prefix app run test:e2e:ci
```

`docker-compose.e2e.yml` mounts `scripts/e2e/nextcloud-config/zz-e2e.config.php`
into the container so the Nextcloud App Store is disabled during bootstrap.

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

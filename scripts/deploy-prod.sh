#!/bin/bash
# Deploy learning-nc to Relay (Production)
# Usage: ./scripts/deploy-prod.sh [--php-only | --js-only | --full | --phpstan | --test]
set -eo pipefail

MODE="${1:---full}"
HOST="relais"
CONTAINER="devcloud-app"
APP_PATH="/var/www/html/custom_apps/learning"
# Gate 1 (PHPStan + PHPUnit) runs against a throwaway copy inside the container, never against
# $APP_PATH. Two reasons, both learned the hard way:
#   1. devcloud is a production instance with real learners on it. Analysing $APP_PATH means
#      deploying first and gating afterwards — which is what --php-only and --full used to do,
#      so the "gate" only ever saw code that was already live.
#   2. A release deploy wipes dev dependencies, so $APP_PATH/vendor/bin/phpstan disappears and
#      the documented Gate-1 command dies with "Could not open input file". Found on 2026-08-28
#      while fixing Codeberg #4 — the gate had been unrunnable, and "PHPStan clean" unprovable.
# The staged copy is built from the LOCAL working tree, so it gates the code you are about to
# ship rather than the code already shipped.
GATE_PATH="/tmp/learning-gate"

echo "=== Learning-NC Deploy to $HOST ==="

# Mirrors the local working tree into $GATE_PATH inside the container and makes sure the dev
# toolchain is present there. Idempotent: composer only runs when phpstan is actually missing.
stage_gate_copy() {
  echo "→ Staging analysis copy in $CONTAINER:$GATE_PATH ..."
  ssh "$HOST" "mkdir -p ~/learning-nc/app/{lib,appinfo,tests}"
  rsync -az --delete app/lib/ "$HOST:~/learning-nc/app/lib/"
  rsync -az --delete --include='*/' --include='*.php' --exclude='*' app/tests/ "$HOST:~/learning-nc/app/tests/"
  rsync -az app/composer.json app/phpstan.neon app/phpstan-baseline.neon app/phpunit.xml \
    "$HOST:~/learning-nc/app/"

  ssh "$HOST" "docker exec $CONTAINER mkdir -p $GATE_PATH/lib $GATE_PATH/tests && \
    docker cp ~/learning-nc/app/lib/. $CONTAINER:$GATE_PATH/lib/ && \
    docker cp ~/learning-nc/app/tests/. $CONTAINER:$GATE_PATH/tests/ && \
    for f in composer.json phpstan.neon phpstan-baseline.neon phpunit.xml; do \
      docker cp ~/learning-nc/app/\$f $CONTAINER:$GATE_PATH/\$f; \
    done"

  # The independent Ed25519 verifier the signing tests insist on (they fail rather than skip).
  scp -q scripts/verify-credential.py "$HOST:/tmp/verify-credential.py"
  ssh "$HOST" "docker cp /tmp/verify-credential.py $CONTAINER:/tmp/verify-credential.py"

  ssh "$HOST" "docker exec $CONTAINER test -f $GATE_PATH/vendor/bin/phpstan" 2>/dev/null || {
    echo "→ Dev toolchain missing in the staged copy — installing (one-off, a few minutes)..."
    ssh "$HOST" "docker exec -w $GATE_PATH $CONTAINER bash -c '
      test -f /tmp/composer || {
        curl -sS -o /tmp/composer-setup.php https://getcomposer.org/installer &&
        php /tmp/composer-setup.php --install-dir=/tmp --filename=composer --quiet
      } &&
      php /tmp/composer install --no-interaction --no-progress'"
  }
}

run_phpstan() {
  stage_gate_copy
  echo "→ Running PHPStan analysis (staged copy — production app untouched)..."
  if ! ssh "$HOST" "docker exec -w $GATE_PATH $CONTAINER php vendor/bin/phpstan analyse --no-progress 2>&1"; then
    echo "✗ PHPStan found errors — deploy aborted!"
    exit 1
  fi
  echo "✓ PHPStan clean"
}

deploy_php() {
  echo "→ Syncing PHP + l10n to $HOST..."
  ssh "$HOST" "mkdir -p ~/learning-nc/app/{lib,appinfo,l10n,templates}"
  rsync -az --exclude node_modules --exclude .git --exclude .planning --exclude build \
    app/lib/ "$HOST:~/learning-nc/app/lib/"
  rsync -az app/templates/ "$HOST:~/learning-nc/app/templates/"
  scp -q app/appinfo/routes.php "$HOST:~/learning-nc/app/appinfo/"
  scp -q app/appinfo/info.xml "$HOST:~/learning-nc/app/appinfo/"
  # Sync all 6 supported language files (en/de/fr/ru/ar/uk) — parity-script enforces same key-set across all
  for lang in de en fr ru ar uk; do
    for ext in json js; do
      f="app/l10n/${lang}.${ext}"
      [ -f "$f" ] && scp -q "$f" "$HOST:~/learning-nc/app/l10n/"
    done
  done

  echo "→ Docker cp via tar..."
  ssh "$HOST" "cd ~/learning-nc/app && \
    tar cf /tmp/php-bundle.tar lib/ appinfo/ l10n/ templates/ && \
    docker cp /tmp/php-bundle.tar $CONTAINER:/tmp/ && \
    docker exec $CONTAINER bash -c 'cd $APP_PATH && tar xf /tmp/php-bundle.tar' && \
    docker exec $CONTAINER apache2ctl graceful"
  echo "→ Verifying deploy..."
  ssh "$HOST" "docker exec $CONTAINER php -r \"require '$APP_PATH/lib/AppInfo/Application.php';\" 2>&1 || echo 'WARN: PHP syntax issue detected'"
  ssh "$HOST" "docker exec $CONTAINER bash -c 'test -L /var/www/html/apps/learning || ln -sf $APP_PATH /var/www/html/apps/learning'"
  echo "✓ PHP deployed"
}

deploy_js() {
  local REPO_ROOT
  REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"

  echo "→ Building JS locally..."
  cd "$REPO_ROOT/app"
  npm install --no-audit --no-fund >/dev/null
  npm run build 2>&1
  if [ $? -ne 0 ]; then
    echo "✗ Vite build failed — deploy aborted!"
    exit 1
  fi
  cd "$REPO_ROOT"

  # Re-sync static CSS that Vite doesn't produce
  rsync -az app/css/style.css app/css/practicum.css "$HOST:~/learning-nc/app/css/" 2>/dev/null || true

  echo "→ Deploying JS + CSS bundles to $HOST..."
  ssh "$HOST" "docker exec $CONTAINER bash -c 'find $APP_PATH/js/ -type f -delete; find $APP_PATH/css/ -type f -delete'"
  tar cf - app/js/ app/css/ --transform='s|^app/||' | ssh "$HOST" "docker exec -i $CONTAINER tar xf - -C $APP_PATH/"
  ssh "$HOST" "docker exec $CONTAINER bash -c 'test -L /var/www/html/apps/learning || ln -sf $APP_PATH /var/www/html/apps/learning'"
  echo "✓ JS + CSS deployed"
}

run_phpunit() {
  stage_gate_copy
  echo "→ Running PHPUnit tests (staged copy)..."
  if ! ssh "$HOST" "docker exec -w $GATE_PATH $CONTAINER php vendor/bin/phpunit 2>&1"; then
    echo "✗ PHPUnit tests failed!"
    exit 1
  fi
  echo "✓ PHPUnit clean"
}

case "$MODE" in
  --help|-h)     echo "Usage: $0 [--php-only | --js-only | --full | --phpstan | --test | --stage-gate]"; exit 0 ;;
  --php-only)    run_phpstan; deploy_php ;;
  --js-only)     deploy_js ;;
  --full)        run_phpstan; deploy_php; deploy_js ;;
  --phpstan)     run_phpstan ;;
  --test)        run_phpstan; run_phpunit ;;
  # For the git hooks: refresh the staged copy without running anything against it.
  --stage-gate)  stage_gate_copy ;;
  *) echo "Usage: $0 [--php-only | --js-only | --full | --phpstan | --test | --stage-gate]"; exit 1 ;;
esac

echo "=== Deploy complete ==="

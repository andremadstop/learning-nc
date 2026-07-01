#!/bin/bash
# Deploy learning-nc to Relay (Production)
# Usage: ./scripts/deploy-prod.sh [--php-only | --js-only | --full | --phpstan | --test]
set -eo pipefail

MODE="${1:---full}"
HOST="relais"
CONTAINER="devcloud-app"
APP_PATH="/var/www/html/custom_apps/learning"

echo "=== Learning-NC Deploy to $HOST ==="

run_phpstan() {
  echo "→ Running PHPStan analysis..."
  rsync -az app/phpstan.neon app/phpstan-baseline.neon "$HOST:~/learning-nc/app/" 2>/dev/null
  ssh "$HOST" "docker cp ~/learning-nc/app/phpstan.neon $CONTAINER:$APP_PATH/phpstan.neon && \
    docker cp ~/learning-nc/app/phpstan-baseline.neon $CONTAINER:$APP_PATH/phpstan-baseline.neon" 2>/dev/null
  ssh "$HOST" "docker exec -w $APP_PATH $CONTAINER php vendor/bin/phpstan analyse --no-progress 2>&1"
  if [ $? -ne 0 ]; then
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
  # Sync all 5 supported language files (en/de/fr/ru/ar) — parity-script enforces same key-set across all
  for lang in de en fr ru ar; do
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
  echo "→ Running PHPUnit tests..."
  rsync -az --include='*.php' --exclude='*.js' app/tests/ "$HOST:~/learning-nc/app/tests/"
  ssh "$HOST" "docker cp ~/learning-nc/app/tests/. $CONTAINER:$APP_PATH/tests/ 2>/dev/null && \
    docker exec -w $APP_PATH $CONTAINER php vendor/bin/phpunit 2>&1"
  if [ $? -ne 0 ]; then
    echo "✗ PHPUnit tests failed!"
    exit 1
  fi
  echo "✓ PHPUnit clean"
}

case "$MODE" in
  --help|-h)     echo "Usage: $0 [--php-only | --js-only | --full | --phpstan | --test]"; exit 0 ;;
  --php-only)    deploy_php; run_phpstan ;;
  --js-only)     deploy_js ;;
  --full)        deploy_php; run_phpstan; deploy_js ;;
  --phpstan)     run_phpstan ;;
  --test)        run_phpstan; run_phpunit ;;
  *) echo "Usage: $0 [--php-only | --js-only | --full | --phpstan | --test]"; exit 1 ;;
esac

echo "=== Deploy complete ==="

#!/bin/bash
# Deploy learning-nc to learning-dev Docker container
# Usage: ./scripts/deploy-dev.sh [--php-only | --js-only | --full]
set -eo pipefail

MODE="${1:---full}"
HOST="learning-dev"
CONTAINER="learning-app"
APP_PATH="/var/www/html/custom_apps/learning"

echo "=== Learning-NC Deploy to $HOST ==="

run_phpstan() {
  echo "→ Running PHPStan analysis..."
  # Sync config + baseline first
  rsync -az app/phpstan.neon app/phpstan-baseline.neon "$HOST:~/learning-nc/app/" 2>/dev/null
  ssh "$HOST" "docker cp ~/learning-nc/app/phpstan.neon $CONTAINER:$APP_PATH/phpstan.neon && \
    docker cp ~/learning-nc/app/phpstan-baseline.neon $CONTAINER:$APP_PATH/phpstan-baseline.neon" 2>/dev/null
  # Run analysis after files are in container (called again after deploy_php copies lib/)
  ssh "$HOST" "docker exec -w $APP_PATH $CONTAINER php vendor/bin/phpstan analyse --no-progress 2>&1"
  if [ $? -ne 0 ]; then
    echo "✗ PHPStan found errors — deploy aborted!"
    exit 1
  fi
  echo "✓ PHPStan clean"
}

deploy_php() {
  echo "→ Syncing PHP + l10n..."
  rsync -az --exclude node_modules --exclude .git --exclude .planning --exclude build \
    app/lib/ "$HOST:~/learning-nc/app/lib/"
  rsync -az app/templates/ "$HOST:~/learning-nc/app/templates/"
  scp -q app/appinfo/routes.php "$HOST:~/learning-nc/app/appinfo/"
  scp -q app/appinfo/info.xml "$HOST:~/learning-nc/app/appinfo/"
  for f in app/l10n/de.json app/l10n/de.js; do
    [ -f "$f" ] && scp -q "$f" "$HOST:~/learning-nc/app/l10n/"
  done

  echo "→ Docker cp via tar (avoids SSHFS stream corruption)..."
  ssh "$HOST" "cd ~/learning-nc/app && \
    tar cf /tmp/php-bundle.tar lib/ appinfo/ l10n/ templates/ && \
    docker cp /tmp/php-bundle.tar $CONTAINER:/tmp/ && \
    docker exec $CONTAINER bash -c 'cd $APP_PATH && tar xf /tmp/php-bundle.tar' && \
    docker exec $CONTAINER apache2ctl graceful"
  echo "→ Verifying deploy..."
  ssh "$HOST" "docker exec $CONTAINER php -r \"require '$APP_PATH/lib/AppInfo/Application.php';\" 2>&1 || echo 'WARN: PHP syntax issue detected'"
  # Ensure apps/learning symlink exists (lost after container restarts)
  ssh "$HOST" "docker exec $CONTAINER bash -c 'test -L /var/www/html/apps/learning || ln -sf $APP_PATH /var/www/html/apps/learning'"
  echo "✓ PHP deployed"
}

deploy_js() {
  echo "→ Syncing Vue sources..."
  rsync -az app/src/ "$HOST:~/learning-nc/app/src/"
  rsync -az app/css/ "$HOST:~/learning-nc/app/css/"
  rsync -az app/package.json app/package-lock.json app/vite.config.mjs app/build-vite.mjs "$HOST:~/learning-nc/app/"

  echo "→ Installing frontend dependencies + building..."
  ssh "$HOST" "cd ~/learning-nc/app && npm install --no-audit --no-fund >/dev/null && npm run build 2>&1"
  if [ $? -ne 0 ]; then
    echo "✗ Vite build failed — deploy aborted!"
    exit 1
  fi

  # Re-sync static CSS that Vite doesn't produce (style.css, practicum.css)
  rsync -az app/css/style.css app/css/practicum.css "$HOST:~/learning-nc/app/css/" 2>/dev/null || true

  echo "→ Deploying JS + CSS bundles..."
  # Clean old bundles in container
  ssh "$HOST" "docker exec $CONTAINER bash -c 'find $APP_PATH/js/ -type f -delete; find $APP_PATH/css/ -type f -delete'"
  # JS via tar (large single file works fine)
  ssh "$HOST" "cd ~/learning-nc/app && tar cf /tmp/js-bundle.tar js/ && docker cp /tmp/js-bundle.tar $CONTAINER:/tmp/ && docker exec $CONTAINER bash -c 'cd $APP_PATH && tar xf /tmp/js-bundle.tar'"
  # CSS via individual docker cp (tar loses CSS files over SSHFS streams)
  ssh "$HOST" "cd ~/learning-nc/app && for f in css/*.css; do docker cp \"\$f\" $CONTAINER:$APP_PATH/css/; done"
  # Ensure apps/learning symlink exists (lost after container restarts)
  ssh "$HOST" "docker exec $CONTAINER bash -c 'test -L /var/www/html/apps/learning || ln -sf $APP_PATH /var/www/html/apps/learning'"
  echo "✓ JS + CSS deployed"
}

run_phpunit() {
  echo "→ Running PHPUnit tests..."
  # Sync test files
  rsync -az --include='*.php' --exclude='*.js' app/tests/ "$HOST:~/learning-nc/app/tests/"
  ssh "$HOST" "for f in tests/Support/PhpUnitStubs.php tests/Support/FakeInfrastructure.php tests/bootstrap.php; do \
    docker cp ~/learning-nc/app/\$f $CONTAINER:$APP_PATH/\$f 2>/dev/null; done && \
    find ~/learning-nc/app/tests/Unit -name '*.php' -exec sh -c 'docker cp \$1 $CONTAINER:$APP_PATH/\${1#*~/learning-nc/app/}' _ {} \; && \
    docker exec -w $APP_PATH $CONTAINER php vendor/bin/phpunit 2>&1"
  if [ $? -ne 0 ]; then
    echo "✗ PHPUnit tests failed — deploy aborted!"
    exit 1
  fi
  echo "✓ PHPUnit clean"
}

case "$MODE" in
  --php-only)    deploy_php; run_phpstan ;;
  --js-only)     deploy_js ;;
  --full)        deploy_php; run_phpstan; deploy_js ;;
  --phpstan)     run_phpstan ;;
  --test)        run_phpstan; run_phpunit ;;
  *) echo "Usage: $0 [--php-only | --js-only | --full | --phpstan | --test]"; exit 1 ;;
esac

echo "=== Deploy complete ==="

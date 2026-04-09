#!/bin/bash
# Deploy learning-nc to Relay (Production) Docker container
# Usage: ./scripts/deploy-prod.sh [--php-only | --js-only | --full]
#
# IMPORTANT: Run deploy-dev.sh first! PHPStan + tests run on staging only.
# This script deploys pre-validated code to production.
set -eo pipefail

MODE="${1:---full}"
HOST="relais"
CONTAINER="devcloud-app"
APP_PATH="/var/www/html/custom_apps/learning"

echo "=== Learning-NC Deploy to PROD ($HOST) ==="
echo "⚠  Make sure deploy-dev.sh passed all gates first!"
echo ""

deploy_php() {
  echo "→ Syncing PHP + l10n to $HOST..."
  ssh "$HOST" "mkdir -p ~/learning-nc/app/{lib,appinfo,l10n,templates}"
  rsync -az --exclude node_modules --exclude .git --exclude .planning --exclude build \
    app/lib/ "$HOST:~/learning-nc/app/lib/"
  rsync -az app/templates/ "$HOST:~/learning-nc/app/templates/"
  scp -q app/appinfo/routes.php "$HOST:~/learning-nc/app/appinfo/"
  scp -q app/appinfo/info.xml "$HOST:~/learning-nc/app/appinfo/"
  for f in app/l10n/de.json app/l10n/de.js; do
    [ -f "$f" ] && scp -q "$f" "$HOST:~/learning-nc/app/l10n/"
  done

  echo "→ Docker cp via tar..."
  ssh "$HOST" "cd ~/learning-nc/app && \
    tar cf /tmp/php-bundle.tar lib/ appinfo/ l10n/ templates/ && \
    docker cp /tmp/php-bundle.tar $CONTAINER:/tmp/ && \
    docker exec $CONTAINER bash -c 'cd $APP_PATH && tar xf /tmp/php-bundle.tar' && \
    docker exec $CONTAINER apache2ctl graceful"
  echo "→ Verifying deploy..."
  ssh "$HOST" "docker exec $CONTAINER php -r \"require '$APP_PATH/lib/AppInfo/Application.php';\" 2>&1 || echo 'WARN: PHP syntax issue detected'"
  # Ensure apps/learning symlink exists (lost after container restarts)
  ssh "$HOST" "docker exec $CONTAINER bash -c 'test -L /var/www/html/apps/learning || ln -sf $APP_PATH /var/www/html/apps/learning'"
  echo "✓ PHP deployed to PROD"
}

deploy_js() {
  echo "→ Building JS locally..."
  cd "$(dirname "$0")/../app"
  npm install --no-audit --no-fund >/dev/null
  npm run build 2>&1
  if [ $? -ne 0 ]; then
    echo "✗ Vite build failed — deploy aborted!"
    exit 1
  fi
  cd "$(dirname "$0")/.."

  echo "→ Deploying JS + CSS bundles to $HOST..."
  # Clean old bundles in container
  ssh "$HOST" "docker exec $CONTAINER bash -c 'find $APP_PATH/js/ -type f -delete; find $APP_PATH/css/ -type f -delete'"
  # Pipe LOCAL js/ + css/ directly into container
  tar cf - app/js/ app/css/ --transform='s|^app/||' | ssh "$HOST" "docker exec -i $CONTAINER tar xf - -C $APP_PATH/"
  # Ensure apps/learning symlink exists
  ssh "$HOST" "docker exec $CONTAINER bash -c 'test -L /var/www/html/apps/learning || ln -sf $APP_PATH /var/www/html/apps/learning'"
  echo "✓ JS + CSS deployed to PROD"
}

case "$MODE" in
  --php-only)    deploy_php ;;
  --js-only)     deploy_js ;;
  --full)        deploy_php; deploy_js ;;
  *) echo "Usage: $0 [--php-only | --js-only | --full]"; exit 1 ;;
esac

echo "=== PROD Deploy complete ==="

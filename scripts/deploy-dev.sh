#!/bin/bash
# Deploy learning-nc to learning-dev Docker container
# Usage: ./scripts/deploy-dev.sh [--php-only | --js-only | --full]
set -e

MODE="${1:---full}"
HOST="learning-dev"
CONTAINER="learning-app"
APP_PATH="/var/www/html/custom_apps/learning"

echo "=== Learning-NC Deploy to $HOST ==="

deploy_php() {
  echo "→ Syncing PHP + l10n..."
  rsync -az --exclude node_modules --exclude .git --exclude .planning --exclude build \
    app/lib/ "$HOST:~/learning-nc/app/lib/"
  scp -q app/appinfo/routes.php "$HOST:~/learning-nc/app/appinfo/"
  scp -q app/appinfo/info.xml "$HOST:~/learning-nc/app/appinfo/"
  for f in app/l10n/de.json app/l10n/de.js; do
    [ -f "$f" ] && scp -q "$f" "$HOST:~/learning-nc/app/l10n/"
  done

  echo "→ Docker cp + OPcache reset..."
  ssh "$HOST" "docker cp ~/learning-nc/app/lib/. $CONTAINER:$APP_PATH/lib/ && \
    docker cp ~/learning-nc/app/appinfo/. $CONTAINER:$APP_PATH/appinfo/ && \
    docker cp ~/learning-nc/app/l10n/. $CONTAINER:$APP_PATH/l10n/ && \
    docker exec $CONTAINER apache2ctl graceful"
  echo "✓ PHP deployed"
}

deploy_js() {
  echo "→ Syncing Vue sources..."
  rsync -az app/src/ "$HOST:~/learning-nc/app/src/"

  echo "→ Building frontend (takes ~55s)..."
  ssh "$HOST" "cd ~/learning-nc/app && npm run build 2>&1 | tail -3"

  echo "→ Deploying JS bundle..."
  ssh "$HOST" "cd ~/learning-nc/app && tar cf /tmp/js-bundle.tar js/ && \
    docker cp /tmp/js-bundle.tar $CONTAINER:/tmp/ && \
    docker exec $CONTAINER bash -c 'cd $APP_PATH && tar xf /tmp/js-bundle.tar'"
  echo "✓ JS deployed"
}

case "$MODE" in
  --php-only) deploy_php ;;
  --js-only)  deploy_js ;;
  --full)     deploy_php; deploy_js ;;
  *) echo "Usage: $0 [--php-only | --js-only | --full]"; exit 1 ;;
esac

echo "=== Deploy complete ==="

#!/usr/bin/env bash
# nc-integration.sh — run app/tests/Integration against a real Nextcloud (no OCP stubs).
#
# Usage: scripts/nc-integration.sh [--index | --ref <git-ref>]
#
# Installs Nextcloud 33 on PostgreSQL 16 in throwaway containers, copies the app from a
# clean snapshot (default: the git index, i.e. what would be committed) to apps/learning,
# runs `occ app:enable learning`, starts the mock LLM server (tests/Integration/Support/
# MockLlmServer.php on 127.0.0.1:18080 inside the Nextcloud container) and runs PHPUnit
# with tests/Integration/phpunit.xml as www-data.
# PHPUnit is a pinned, SHA-256-verified PHAR — not the app's composer vendor, which ships
# nextcloud/ocp stubs that would shadow the real server API.
# Containers (with their volumes) and network carry a per-run label and are always removed
# by that label; a failed removal exits 3.
# Exit 0 only if PHPUnit passed and cleanup succeeded.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
NC_IMAGE="${INTEG_NC_IMAGE:-nextcloud:33@sha256:df735d59202b74546ca4f935ec860d8397995a6e4e353926c4876547f6c6c4a5}"
PG_IMAGE="${INTEG_PG_IMAGE:-postgres:16-alpine@sha256:721873c34ceb9f8d8fc265984940dc982404c105f19ad51be9fdc5970a6080ea}"
PHPUNIT_VERSION="10.5.65"
PHPUNIT_SHA256="ce85745a2ec7d8e536621ebed69dfa36a8d504daf73be6750e93bb8c5b088835"
MOCK_URL="http://127.0.0.1:18080/v1/chat/completions"

SRC="${1:---index}"

RUN_ID="$$-$RANDOM$RANDOM"
LABEL="learning-gate.run=$RUN_ID"   # every resource carries it; cleanup finds them by it
NET="learning-gate-integ-net-$RUN_ID"
DB="learning-gate-integ-db-$RUN_ID"
NC="learning-gate-integ-nc-$RUN_ID"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/learning-integ.XXXXXX")"
DB_PASS="throwaway-$RUN_ID"   # dies with the container; never leaves this machine

# Cleanup removes exactly what carries this run's label — also resources whose creation
# was interrupted before the create command returned. Each removal is verified; a failed
# cleanup turns a successful exit into exit 3.
cleanup() {
	local rc=$? ok=1 ids
	ids="$(docker ps -aq --filter "label=$LABEL")" || ok=0
	if [ -n "$ids" ]; then
		# shellcheck disable=SC2086  # word splitting of the id list is intended
		docker rm -f -v $ids >/dev/null || ok=0
	fi
	ids="$(docker network ls -q --filter "label=$LABEL")" || ok=0
	if [ -n "$ids" ]; then
		# shellcheck disable=SC2086
		docker network rm $ids >/dev/null || ok=0
	fi
	if [ -n "$(docker ps -aq --filter "label=$LABEL")$(docker network ls -q --filter "label=$LABEL")" ]; then
		ok=0
	fi
	[ "$ok" = 1 ] || echo "nc-integration: CLEANUP FAILED: resources with label $LABEL" >&2
	if ! rm -r -- "$WORK"; then echo "nc-integration: CLEANUP FAILED: $WORK" >&2; ok=0; fi
	if [ "$ok" = 0 ] && [ "$rc" -eq 0 ]; then rc=3; fi
	exit "$rc"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

case "$SRC" in
	--index) git -C "$REPO_ROOT" checkout-index -a --prefix="$WORK/repo/" ;;
	--ref)   mkdir -p "$WORK/repo"; git -C "$REPO_ROOT" archive "${2:?--ref needs a git ref}" | tar -x -C "$WORK/repo" ;;  # pipefail is set
	*)       echo "usage: $0 [--index | --ref <git-ref>]" >&2; exit 2 ;;
esac
[ -f "$WORK/repo/app/tests/Integration/phpunit.xml" ] || { echo "snapshot has no app/tests/Integration/phpunit.xml" >&2; exit 2; }

echo "==> network $NET, containers $DB + $NC"
docker network create --label "$LABEL" "$NET" >/dev/null
docker create --name "$DB" --label "$LABEL" --network "$NET" \
	-e POSTGRES_USER=nc -e POSTGRES_PASSWORD="$DB_PASS" -e POSTGRES_DB=nextcloud "$PG_IMAGE" >/dev/null
docker create --name "$NC" --label "$LABEL" --network "$NET" --entrypoint sleep "$NC_IMAGE" infinity >/dev/null
docker start "$DB" "$NC" >/dev/null

# Over TCP: during initdb the image runs a socket-only temporary server.
ready=0
for _ in $(seq 60); do
	if docker exec "$DB" pg_isready -h 127.0.0.1 -U nc -d nextcloud -q 2>/dev/null; then ready=1; break; fi
	sleep 1
done
[ "$ready" = 1 ] || { echo "PostgreSQL did not come up" >&2; exit 1; }

echo "==> PHPUnit $PHPUNIT_VERSION (PHAR, verified)"
docker exec -e V="$PHPUNIT_VERSION" -e SUM="$PHPUNIT_SHA256" "$NC" sh -euc '
	curl -fsSL -o /usr/local/bin/phpunit "https://phar.phpunit.de/phpunit-$V.phar"
	echo "$SUM  /usr/local/bin/phpunit" | sha256sum -c --quiet
	chmod +x /usr/local/bin/phpunit
'

echo "==> install Nextcloud + enable learning"
docker exec "$NC" sh -euc 'cp -a /usr/src/nextcloud/. /var/www/html/ && mkdir -p /var/www/html/data'
docker cp "$WORK/repo/app/." "$NC:/var/www/html/apps/learning"
docker exec "$NC" chown -R www-data:www-data /var/www/html
docker exec -u www-data -w /var/www/html "$NC" php occ maintenance:install -n \
	--database=pgsql --database-host="$DB" --database-name=nextcloud \
	--database-user=nc --database-pass="$DB_PASS" \
	--admin-user=admin --admin-pass="$DB_PASS" >/dev/null
docker exec -u www-data -w /var/www/html "$NC" php occ app:enable learning

echo "==> mock LLM server on 127.0.0.1:18080"
# The log must exist before the server starts: the mock refuses to log into a missing file.
docker exec -u www-data "$NC" sh -euc ': > /tmp/llm-requests.log'
docker exec -d -u www-data "$NC" php -S 127.0.0.1:18080 /var/www/html/apps/learning/tests/Integration/Support/MockLlmServer.php
ready=0
for _ in $(seq 20); do
	if docker exec "$NC" curl -fsS -o /dev/null -X POST -H 'Content-Type: application/json' \
		-d '{"model":"readiness-probe"}' "$MOCK_URL" 2>/dev/null; then ready=1; break; fi
	sleep 0.5
done
[ "$ready" = 1 ] || { echo "mock LLM server did not answer" >&2; exit 1; }
docker exec "$NC" grep -q readiness-probe /tmp/llm-requests.log || { echo "mock LLM server did not log the request" >&2; exit 1; }
docker exec -u www-data "$NC" sh -euc ': > /tmp/llm-requests.log'   # tests start with an empty log

echo "==> PHPUnit (tests/Integration)"
docker exec -u www-data -w /var/www/html/apps/learning "$NC" \
	php /usr/local/bin/phpunit -c tests/Integration/phpunit.xml

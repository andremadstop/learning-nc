#!/usr/bin/env bash
# schema-dump.sh — dump the learning tables' columns from a fresh Nextcloud + PostgreSQL.
#
# Usage: scripts/schema-dump.sh <out-file> [--index | --ref <git-ref>]
#
# Installs Nextcloud 33 on PostgreSQL 16 in throwaway containers, enables the app from a
# clean snapshot (default: the git index, i.e. what would be committed) so its migrations
# create the schema, and writes information_schema.columns for oc_learning% to <out-file>
# as `table|column` lines — the format scripts/check-sql-columns.py reads via SCHEMA_FILE.
# Containers (with their volumes) and network are always removed; a failed removal exits 3.
# Exit 0 only if the dump is non-empty and cleanup succeeded.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# Git operations use GATE_GIT_ROOT when set: the hooks run this script from a snapshot of the
# commit under test (so an uncommitted edit to the gate cannot vouch for the commit), and the
# snapshot itself is not the repository.
GIT_ROOT="${GATE_GIT_ROOT:-$REPO_ROOT}"
NC_IMAGE="${SCHEMA_NC_IMAGE:-nextcloud:33@sha256:df735d59202b74546ca4f935ec860d8397995a6e4e353926c4876547f6c6c4a5}"
PG_IMAGE="${SCHEMA_PG_IMAGE:-postgres:16-alpine@sha256:721873c34ceb9f8d8fc265984940dc982404c105f19ad51be9fdc5970a6080ea}"

OUT="${1:-}"
SRC="${2:---index}"
[ -n "$OUT" ] || { echo "usage: $0 <out-file> [--index | --ref <git-ref>]" >&2; exit 2; }

NET="learning-gate-schema-$$"
DB="learning-gate-schemadb-$$"
NC="learning-gate-schemanc-$$"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/learning-schema.XXXXXX")"
DB_PASS="throwaway-$$-$RANDOM"   # dies with the container; never leaves this machine

# Only resources this run actually created are removed (flags set right after creation),
# each removal is verified, and a failed cleanup turns a successful exit into exit 3.
# -v also removes the anonymous volumes both images declare.
HAVE_NET=0; HAVE_DB=0; HAVE_NC=0
cleanup() {
	local rc=$? ok=1 c
	for c in "$NC:$HAVE_NC" "$DB:$HAVE_DB"; do
		[ "${c##*:}" = 1 ] || continue
		c="${c%:*}"
		if ! docker rm -f -v "$c" >/dev/null || docker container inspect "$c" >/dev/null 2>&1; then
			echo "schema-dump: CLEANUP FAILED: container $c" >&2; ok=0
		fi
	done
	if [ "$HAVE_NET" = 1 ]; then
		if ! docker network rm "$NET" >/dev/null || docker network inspect "$NET" >/dev/null 2>&1; then
			echo "schema-dump: CLEANUP FAILED: network $NET" >&2; ok=0
		fi
	fi
	if ! rm -r -- "$WORK"; then echo "schema-dump: CLEANUP FAILED: $WORK" >&2; ok=0; fi
	if [ "$ok" = 0 ] && [ "$rc" -eq 0 ]; then rc=3; fi
	exit "$rc"
}
trap cleanup EXIT

case "$SRC" in
	--index) git -C "$GIT_ROOT" checkout-index -a --prefix="$WORK/repo/" ;;
	--ref)   mkdir -p "$WORK/repo"; git -C "$GIT_ROOT" archive "${3:?--ref needs a git ref}" | tar -x -C "$WORK/repo" ;;  # pipefail is set
	*)       echo "unknown source: $SRC" >&2; exit 2 ;;
esac
[ -f "$WORK/repo/app/appinfo/info.xml" ] || { echo "snapshot has no app/appinfo/info.xml" >&2; exit 2; }

echo "==> network $NET, containers $DB + $NC"
docker network create "$NET" >/dev/null; HAVE_NET=1
# create + start separately: a container that was created but failed to start is still ours.
docker create --name "$DB" --network "$NET" \
	-e POSTGRES_USER=nc -e POSTGRES_PASSWORD="$DB_PASS" -e POSTGRES_DB=nextcloud "$PG_IMAGE" >/dev/null; HAVE_DB=1
docker create --name "$NC" --network "$NET" --entrypoint sleep "$NC_IMAGE" infinity >/dev/null; HAVE_NC=1
docker start "$DB" "$NC" >/dev/null

# Over TCP: during initdb the image runs a socket-only temporary server that answers
# pg_isready on the socket and is then restarted.
ready=0
for _ in $(seq 60); do
	if docker exec "$DB" pg_isready -h 127.0.0.1 -U nc -d nextcloud -q 2>/dev/null; then ready=1; break; fi
	sleep 1
done
[ "$ready" = 1 ] || { echo "PostgreSQL did not come up" >&2; exit 1; }

echo "==> install Nextcloud + enable learning"
docker exec "$NC" sh -euc 'cp -a /usr/src/nextcloud/. /var/www/html/ && mkdir -p /var/www/html/data'
docker cp "$WORK/repo/app/." "$NC:/var/www/html/apps/learning"
docker exec "$NC" chown -R www-data:www-data /var/www/html
docker exec -u www-data -w /var/www/html "$NC" php occ maintenance:install -n \
	--database=pgsql --database-host="$DB" --database-name=nextcloud \
	--database-user=nc --database-pass="$DB_PASS" \
	--admin-user=admin --admin-pass="$DB_PASS" >/dev/null
docker exec -u www-data -w /var/www/html "$NC" php occ app:enable learning

echo "==> dump schema"
docker exec "$DB" psql -U nc -d nextcloud -tA -F '|' -c \
	"select table_name, column_name from information_schema.columns
	 where table_schema = current_schema() and table_name like 'oc_learning%'
	 order by table_name, column_name" > "$WORK/schema.txt"
[ -s "$WORK/schema.txt" ] || { echo "schema dump is empty — did app:enable run the migrations?" >&2; exit 1; }
cp -- "$WORK/schema.txt" "$OUT"
echo "schema-dump: $(cut -d'|' -f1 "$OUT" | sort -u | wc -l) tables, $(wc -l < "$OUT") columns -> $OUT"

#!/usr/bin/env bash
# inprocess-update-probe.sh — Update so, wie es ein Store-Update macht: im selben PHP-Prozess.
#
#   scripts/inprocess-update-probe.sh <alter-tarball> <neuer-tarball-oder-app-verzeichnis>
#   ENGINE=pgsql (Standard) | mysql
#
# Frisches NC 33 + Datenbank im Wegwerf-Container, <alt> installiert und aktiviert, dann
# scripts/inprocess-update-harness.php: bootet mit der alten App, lädt die occ-Befehle wie
# console.php (damit sind die alten App-Klassen im Prozess), ersetzt apps/learning durch <neu> und
# ruft AppManager::upgradeApp() im selben Prozess — der Pfad von `occ app:update` und der
# App-Verwaltung. Boot-Gate und Lifecycle-Probe kopieren die Dateien und starten einen frischen
# Prozess; sie sehen nicht, wenn eine Migration eine im Release geänderte App-Klasse liest
# (5.18.0: Version011600 + TranslationService::ALLOWED_LANGS, auf PostgreSQL „Cannot access
# private constant“, Heim-Cloud blieb auf needsDbUpgrade stehen).
#
# Prüft: Harness exit 0, installed_version == Version aus <neu>, needsDbUpgrade false, alle
# Migrationen aus <neu>/lib/Migration in oc_migrations, keine learning-Fehler im Log.
# Gate 4, vor jedem Release vom zuletzt veröffentlichten Tarball aus (und, wenn eine Version in
# der Fläche hängen bleiben kann, auch von der davor).
set -uo pipefail
OLD="${1:?alter Tarball}"; NEW="${2:?neuer Tarball oder App-Verzeichnis}"
ENGINE="${ENGINE:-pgsql}"
HERE=$(cd "$(dirname "$0")" && pwd)
NC_IMAGE="${NC_IMAGE:-nextcloud:33@sha256:df735d59202b74546ca4f935ec860d8397995a6e4e353926c4876547f6c6c4a5}"
PG_IMAGE="postgres:16-alpine@sha256:721873c34ceb9f8d8fc265984940dc982404c105f19ad51be9fdc5970a6080ea"
MY_IMAGE="mariadb:10.11@sha256:7f22313fc130a377a44999965bcb0a08dd5b21e8502824c1b864f792f9bc66ab"
P="throwaway-$$-$RANDOM"; NET=lrn-iup-net-$$; DB=lrn-iup-db-$$; NC=lrn-iup-nc-$$
W=$(mktemp -d "${TMPDIR:-/tmp}/lrn-iup.XXXXXX"); FAILED=0
cleanup() {
	docker rm -f -v "$NC" "$DB" >/dev/null 2>&1; docker network rm "$NET" >/dev/null 2>&1; rm -r -- "$W"
	for c in "$NC" "$DB"; do docker inspect "$c" >/dev/null 2>&1 && { echo "CLEANUP FAILED: $c"; FAILED=1; }; done
	if [ "$FAILED" = 0 ]; then echo "INPROCESS ENGINE=$ENGINE result=ok"; else echo "INPROCESS ENGINE=$ENGINE result=fail"; exit 1; fi
}
trap cleanup EXIT
ck() { local n=$1; shift; if "$@"; then echo "INPROCESS $n=ok"; else echo "INPROCESS $n=fail"; FAILED=1; fi; }
occ() { docker exec -u www-data -w /var/www/html "$NC" php occ "$@"; }

mkdir -p "$W/old" "$W/new"
tar -xzf "$OLD" -C "$W/old" || { echo "STOP: $OLD nicht lesbar"; FAILED=1; exit 1; }
if [ -d "$NEW" ]; then cp -a "$NEW/." "$W/new/learning"; else tar -xzf "$NEW" -C "$W/new" || { echo "STOP: $NEW nicht lesbar"; FAILED=1; exit 1; }; fi
ver() { sed -n 's#.*<version>\([^<]*\)</version>.*#\1#p' "$1/appinfo/info.xml" | head -1; }
V_OLD=$(ver "$W/old/learning"); V_NEW=$(ver "$W/new/learning")
echo "old=$V_OLD new=$V_NEW engine=$ENGINE"
[ -n "$V_OLD" ] && [ -n "$V_NEW" ] || { echo "STOP: Version nicht lesbar"; FAILED=1; exit 1; }

docker network create "$NET" >/dev/null
if [ "$ENGINE" = mysql ]; then
	docker run -d --name "$DB" --network "$NET" -e MARIADB_ROOT_PASSWORD="$P" -e MARIADB_DATABASE=nextcloud -e MARIADB_USER=nc -e MARIADB_PASSWORD="$P" "$MY_IMAGE" >/dev/null
	for _ in $(seq 90); do docker exec "$DB" mariadb -unc -p"$P" -e 'select 1' nextcloud >/dev/null 2>&1 && break; sleep 1; done
	DBTYPE=mysql
else
	docker run -d --name "$DB" --network "$NET" -e POSTGRES_USER=nc -e POSTGRES_PASSWORD="$P" -e POSTGRES_DB=nextcloud "$PG_IMAGE" >/dev/null
	for _ in $(seq 60); do docker exec "$DB" pg_isready -h 127.0.0.1 -U nc -d nextcloud -q 2>/dev/null && break; sleep 1; done
	DBTYPE=pgsql
fi
sql() {
	if [ "$ENGINE" = mysql ]; then docker exec "$DB" mariadb -unc -p"$P" -N -B nextcloud -e "$1"
	else docker exec "$DB" psql -U nc -d nextcloud -tA -c "$1"; fi
}
docker run -d --name "$NC" --network "$NET" --entrypoint sleep "$NC_IMAGE" infinity >/dev/null
docker exec "$NC" sh -euc 'cp -a /usr/src/nextcloud/. /var/www/html/ && mkdir -p /var/www/html/data'
docker cp "$W/old/learning/." "$NC":/var/www/html/apps/learning
docker exec "$NC" mkdir -p /tmp/new
docker cp "$W/new/learning/." "$NC":/tmp/new/learning
docker cp "$HERE/inprocess-update-harness.php" "$NC":/tmp/inprocess-update-harness.php
docker exec "$NC" chown -R www-data:www-data /var/www/html /tmp/new /tmp/inprocess-update-harness.php
ck install occ maintenance:install -n --database="$DBTYPE" --database-host="$DB" --database-name=nextcloud --database-user=nc --database-pass="$P" --admin-user=admin --admin-pass="$P"
occ app:disable firstrunwizard >/dev/null 2>&1
ck enable_old occ app:enable learning
ck version_old sh -c "docker exec -u www-data -w /var/www/html $NC php occ config:app:get learning installed_version | grep -qxF '$V_OLD'"

LOGPOS=$(docker exec "$NC" sh -c 'cat /var/www/html/data/nextcloud.log 2>/dev/null' | wc -l)
docker exec -u www-data -w /var/www/html "$NC" php /tmp/inprocess-update-harness.php /tmp/new/learning > "$W/harness.txt" 2>&1; rc=$?
cat "$W/harness.txt"; echo "harness exit $rc"
ck harness_loaded_old_classes grep -q 'PROBE translation_service_loaded=yes' "$W/harness.txt"
ck inprocess_upgrade sh -c "[ $rc = 0 ] && grep -qx 'PROBE upgrade=ok' '$W/harness.txt'"
ck version_new sh -c "docker exec -u www-data -w /var/www/html $NC php occ config:app:get learning installed_version | grep -qxF '$V_NEW'"
ck no_db_upgrade_pending sh -c "docker exec -u www-data -w /var/www/html $NC php occ status --output=json | grep -q '\"needsDbUpgrade\":false'"
missing=0
for f in "$W"/new/learning/lib/Migration/Version*.php; do
	v=$(basename "$f" .php); v=${v#Version}
	[ "$(sql "select count(*) from oc_migrations where app='learning' and version='$v'")" = 1 ] || { echo "missing migration: $v"; missing=$((missing + 1)); }
done
ck all_migrations_recorded [ "$missing" = 0 ]
docker exec "$NC" sh -c 'cat /var/www/html/data/nextcloud.log 2>/dev/null' | tail -n +"$((LOGPOS + 1))" > "$W/log-after.txt"
grep '"level":[34]' "$W/log-after.txt" | head -c 800; echo
ck log_no_learning_errors sh -c "! grep -E '\"app\":\"learning\"|OCA\\\\\\\\Learning' '$W/log-after.txt' | grep -q '\"level\":[34]'"
[ "$FAILED" = 0 ]

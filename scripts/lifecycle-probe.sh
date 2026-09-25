#!/usr/bin/env bash
# lifecycle-probe.sh — fresh install and 5.5.1 -> working-state upgrade in throwaway containers.
#
# Usage: ENGINE=pgsql|mysql [PROBE_REF=<git-ref>] [PROBE_FAIL_AFTER=key_write] scripts/lifecycle-probe.sh
#
# Per engine, three scenarios, each on its own Nextcloud 33 + database pair:
#   fresh    install the working state (git index, release file set) and enable it
#   upgrade  install the 5.5.1 release tarball, seed legacy AI fixtures
#            (scripts/lifecycle-fixtures/seed-5.5.1.sql.php), replace the app with the working
#            state, run `occ upgrade`, then `occ maintenance:repair` twice
#   bare     install the 5.5.1 release tarball WITHOUT seeding (a fresh 5.5.1 has no audit chain
#            head and only the legacy translation tables), then upgrade like `upgrade`
# Each scenario ends with scripts/lifecycle-fixtures/check-working-state.php: audit chain head,
# a real compliance event, the expected chain position, and a translation round-trip.
# The working state is the git index, or the tree of PROBE_REF (e.g. to prove the checks go red
# on a release without the fix).
# Every check prints `LIFECYCLE <engine> <check>=ok|fail`; the last line is
# `LIFECYCLE <engine> result=ok|fail`. Exit 0 only if every check passed and cleanup succeeded.
# PROBE_FAIL_AFTER=key_write is handed to the first repair only: the repair step (Task C1) then
# aborts the key migration after writing the new key. That run may fail; the second must pass.
# Only the skeleton checks live here; Tasks C2/C3/E3 add the migration assertions.
# Containers are named learning-gate-life-*-$$ and always removed (exit 3 if that fails).
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENGINE="${ENGINE:-pgsql}"
NC_IMAGE="nextcloud:33@sha256:df735d59202b74546ca4f935ec860d8397995a6e4e353926c4876547f6c6c4a5"
PG_IMAGE="postgres:16-alpine@sha256:721873c34ceb9f8d8fc265984940dc982404c105f19ad51be9fdc5970a6080ea"
MY_IMAGE="mariadb:10.11@sha256:7f22313fc130a377a44999965bcb0a08dd5b21e8502824c1b864f792f9bc66ab"
OLD_URL="https://codeberg.org/andremadstop/learning-nc/releases/download/v5.5.1/learning-5.5.1.tar.gz"
OLD_SHA256="b4a7ff9100d761375d74031bbce6000ea0da89ff851a2873f109cb0ef02d01ef"
OLD_VERSION="5.5.1"
RELEASE_SET=(appinfo css img js l10n lib templates data CHANGELOG.md LICENSE README.md)
SEEDER="$REPO_ROOT/scripts/lifecycle-fixtures/seed-5.5.1.sql.php"
CHECKER="$REPO_ROOT/scripts/lifecycle-fixtures/check-working-state.php"

PROBE_FAIL_AFTER="${PROBE_FAIL_AFTER:-}"
case "$ENGINE" in pgsql|mysql) ;; *) echo "ENGINE must be pgsql or mysql" >&2; exit 2 ;; esac
case "$PROBE_FAIL_AFTER" in ''|key_write) ;; *) echo "PROBE_FAIL_AFTER must be empty or key_write" >&2; exit 2 ;; esac

WORK="$(mktemp -d "${TMPDIR:-/tmp}/learning-life.XXXXXX")"
PASS="throwaway-$$-$RANDOM"   # dies with the containers; never leaves this machine
CREATED=()                     # "container:<name>" / "network:<name>", in creation order
FAILED=0

cleanup() {
	local rc=$? ok=1 i kind name
	for (( i=${#CREATED[@]}-1; i>=0; i-- )); do
		kind="${CREATED[i]%%:*}"; name="${CREATED[i]#*:}"
		if [ "$kind" = container ]; then docker rm -f -v "$name" >/dev/null || true
		else docker network rm "$name" >/dev/null || true; fi
		if docker "$kind" inspect "$name" >/dev/null 2>&1; then
			echo "lifecycle-probe: CLEANUP FAILED: $kind $name" >&2; ok=0
		fi
	done
	rm -r -- "$WORK" || { echo "lifecycle-probe: CLEANUP FAILED: $WORK" >&2; ok=0; }
	if [ "$ok" = 0 ] && [ "$rc" -eq 0 ]; then rc=3; fi
	exit "$rc"
}
trap cleanup EXIT

# check <name> <cmd...> — run a step, print its verdict, keep going on failure.
# errexit does not apply inside an `if` condition, so every step function chains with &&.
check() {
	local name="$1"; shift
	if "$@"; then echo "LIFECYCLE $ENGINE $name=ok"; else echo "LIFECYCLE $ENGINE $name=fail"; FAILED=1; fi
}

# --- sources ----------------------------------------------------------------------------------
if [ -n "${PROBE_REF:-}" ]; then
	echo "==> working state from $PROBE_REF"
	mkdir -p "$WORK/repo"
	git -C "$REPO_ROOT" archive "$PROBE_REF" app | tar -x -C "$WORK/repo"
else
	echo "==> working state from git index"
	git -C "$REPO_ROOT" checkout-index -a --prefix="$WORK/repo/"
fi
mkdir -p "$WORK/new/learning"
for f in "${RELEASE_SET[@]}"; do
	[ -e "$WORK/repo/app/$f" ] || { echo "working state lacks app/$f" >&2; exit 2; }
	cp -a "$WORK/repo/app/$f" "$WORK/new/learning/"
done
rm -f -- "$WORK/new/learning/appinfo/signature.json"   # the unsigned working state, not a stale signature
NEW_VERSION="$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$WORK/new/learning/appinfo/info.xml" | head -1)"

echo "==> $OLD_VERSION release tarball"
curl -fsSL -o "$WORK/old.tar.gz" "$OLD_URL"
echo "$OLD_SHA256  $WORK/old.tar.gz" | sha256sum -c --quiet -
mkdir -p "$WORK/old"; tar -xzf "$WORK/old.tar.gz" -C "$WORK/old"
[ -f "$WORK/old/learning/appinfo/info.xml" ] || { echo "tarball has no learning/appinfo/info.xml" >&2; exit 2; }

# --- instance helpers -------------------------------------------------------------------------
# new_instance <scenario> — start DB + Nextcloud (source copied, not installed); sets DB, NC.
new_instance() {
	local net="learning-gate-life-net-$1-$$"
	DB="learning-gate-life-db-$1-$$"; NC="learning-gate-life-nc-$1-$$"
	docker network create "$net" >/dev/null || return 1
	CREATED+=("network:$net")
	if [ "$ENGINE" = pgsql ]; then
		docker create --name "$DB" --network "$net" -e POSTGRES_USER=nc -e POSTGRES_PASSWORD="$PASS" \
			-e POSTGRES_DB=nextcloud "$PG_IMAGE" >/dev/null || return 1
	else
		docker create --name "$DB" --network "$net" -e MARIADB_ROOT_PASSWORD="$PASS" \
			-e MARIADB_DATABASE=nextcloud -e MARIADB_USER=nc -e MARIADB_PASSWORD="$PASS" "$MY_IMAGE" >/dev/null || return 1
	fi
	CREATED+=("container:$DB")
	docker create --name "$NC" --network "$net" --entrypoint sleep "$NC_IMAGE" infinity >/dev/null || return 1
	CREATED+=("container:$NC")
	docker start "$DB" "$NC" >/dev/null || return 1
	local _
	for _ in $(seq 90); do
		if [ "$ENGINE" = pgsql ]; then
			# TCP, not the socket: initdb's temporary server answers on the socket only.
			docker exec "$DB" pg_isready -h 127.0.0.1 -U nc -d nextcloud -q 2>/dev/null && return 0
		else
			# a real authenticated query; `mariadb-admin ping` races the passwordless init server
			docker exec "$DB" mariadb -unc -p"$PASS" -h 127.0.0.1 -e 'SELECT 1' nextcloud >/dev/null 2>&1 && return 0
		fi
		sleep 1
	done
	echo "database $DB did not come up" >&2; return 1
}

occ() { docker exec -u www-data -w /var/www/html "$NC" php occ "$@"; }

# install_nc <app-dir> — install Nextcloud with the given app tree as apps/learning
install_nc() {
	docker exec "$NC" sh -euc 'cp -a /usr/src/nextcloud/. /var/www/html/ && mkdir -p /var/www/html/data' &&
	docker cp "$1/." "$NC:/var/www/html/apps/learning" &&
	docker exec "$NC" chown -R www-data:www-data /var/www/html &&
	occ maintenance:install -n --database="$ENGINE" --database-host="$DB" --database-name=nextcloud \
		--database-user=nc --database-pass="$PASS" --admin-user=admin --admin-pass="$PASS" >/dev/null
}

installed_version() { occ config:app:get learning installed_version; }

learning_tables() {
	local n
	if [ "$ENGINE" = pgsql ]; then
		n="$(docker exec "$DB" psql -U nc -d nextcloud -tA -c \
			"select count(*) from information_schema.tables where table_schema = current_schema() and table_name like 'oc\\_learning%'")" || return 1
	else
		n="$(docker exec "$DB" mariadb -unc -p"$PASS" -h 127.0.0.1 -N -B -e \
			"select count(*) from information_schema.tables where table_schema = 'nextcloud' and table_name like 'oc\\_learning%'" nextcloud)" || return 1
	fi
	echo "learning tables: $n"
	[ "$n" -gt 0 ]
}

app_enabled() { occ app:list --enabled | grep '^  - learning:' >/dev/null; }  # no -q: SIGPIPE + pipefail

version_is() { local v; v="$(installed_version)" || return 1; echo "installed_version: $v (want $1)"; [ "$v" = "$1" ]; }

seed() {
	local u
	for u in lc-user1 lc-user2 lc-user3; do
		docker exec -u www-data -w /var/www/html -e OC_PASS="$PASS" "$NC" \
			php occ user:add --password-from-env "$u" >/dev/null || return 1
	done
	docker cp "$SEEDER" "$NC:/tmp/seed.php" || return 1
	# Also require the seeder's own report: Nextcloud's error handler can end a script with exit 0.
	local out rc=0
	out="$(docker exec -u www-data "$NC" php /tmp/seed.php 2>&1)" || rc=$?
	echo "$out"
	[ "$rc" -eq 0 ] && grep -q '^SEED chained_rows=3 ' <<<"$out"
}

# working_state <expected_last_seq> — the fresh-install regressions of 5.5.2 are gone
working_state() {
	docker cp "$CHECKER" "$NC:/tmp/check.php" || return 1
	local out rc=0
	out="$(docker exec -u www-data "$NC" php /tmp/check.php "$1" 2>&1)" || rc=$?
	echo "$out"
	[ "$rc" -eq 0 ] && grep -q '^CHECK result=ok$' <<<"$out"
}

# The injected run is expected to abort; only its exit code is reported, the second repair decides.
repair_injected() {
	local rc=0
	docker exec -u www-data -w /var/www/html -e PROBE_FAIL_AFTER="$PROBE_FAIL_AFTER" "$NC" \
		php occ maintenance:repair || rc=$?
	echo "injected repair (PROBE_FAIL_AFTER=$PROBE_FAIL_AFTER) exit code: $rc"
}

# Replace, not overlay: files removed between versions must not survive the upgrade.
swap_to_working_state() {
	docker exec "$NC" sh -euc 'd=/var/www/html/apps/learning; rm -r -- "$d"' &&
	docker cp "$WORK/new/learning/." "$NC:/var/www/html/apps/learning" &&
	docker exec "$NC" chown -R www-data:www-data /var/www/html/apps/learning
}

# --- scenario: fresh install ------------------------------------------------------------------
echo "==> [$ENGINE] fresh install of working state $NEW_VERSION"
check fresh_setup new_instance fresh
check fresh_install install_nc "$WORK/new/learning"
check fresh_enable occ app:enable learning
check fresh_schema learning_tables
check fresh_enabled app_enabled
check fresh_version version_is "$NEW_VERSION"
check fresh_working_state working_state 1

# --- scenario: upgrade 5.5.1 -> working state -------------------------------------------------
echo "==> [$ENGINE] install $OLD_VERSION, seed, upgrade to $NEW_VERSION"
check upgrade_setup new_instance upgrade
check upgrade_install_old install_nc "$WORK/old/learning"
check upgrade_enable_old occ app:enable learning
check upgrade_old_version version_is "$OLD_VERSION"
check seed seed
check upgrade_swap swap_to_working_state
check upgrade_occ_upgrade occ upgrade
check upgrade_new_version version_is "$NEW_VERSION"
if [ -n "$PROBE_FAIL_AFTER" ]; then
	check upgrade_repair_1_injected repair_injected
else
	check upgrade_repair_1 occ maintenance:repair
fi
check upgrade_repair_2 occ maintenance:repair
check upgrade_enabled app_enabled
# 3 chained rows seeded + 1 from the check: the existing head was kept, not re-seeded
check upgrade_working_state working_state 4

# --- scenario: bare 5.5.1 install (no seed) -> working state ----------------------------------
echo "==> [$ENGINE] install $OLD_VERSION without seeding, upgrade to $NEW_VERSION"
check bare_setup new_instance bare
check bare_install_old install_nc "$WORK/old/learning"
check bare_enable_old occ app:enable learning
check bare_swap swap_to_working_state
check bare_occ_upgrade occ upgrade
check bare_new_version version_is "$NEW_VERSION"
check bare_repair occ maintenance:repair
check bare_working_state working_state 1

if [ "$FAILED" = 0 ]; then echo "LIFECYCLE $ENGINE result=ok"; else echo "LIFECYCLE $ENGINE result=fail"; exit 1; fi

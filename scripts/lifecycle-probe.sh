#!/usr/bin/env bash
# lifecycle-probe.sh — fresh install and 5.5.1 -> working-state upgrade in throwaway containers.
#
# Usage: ENGINE=pgsql|mysql [PROBE_REF=<git-ref>] [PROBE_FAIL_AFTER=key_write] [LIFECYCLE_NC_IMAGE=<image>]
#        [RECENT_BASES="5.13.0 5.14.0 5.14.1 5.15.0 5.16.0 5.17.0 5.18.0 5.18.1 5.19.0 5.20.0 5.20.1"] [RECENT_TARBALL_DIR=<dir>] [PROBE_BUMP_VERSION=<x.y.z>]
#          scripts/lifecycle-probe.sh
#
# Per engine, three scenarios, each on its own Nextcloud + database pair. Nextcloud is 33 (pinned
# digest) unless LIFECYCLE_NC_IMAGE names another image (the nightly CI matrix runs 33/34/35);
# like INTEG_NC_IMAGE in nc-integration.sh, unset means exactly the pinned default:
#   fresh    install the working state (git index, release file set) and enable it
#   upgrade  install the 5.5.1 release tarball, seed legacy AI fixtures
#            (scripts/lifecycle-fixtures/seed-5.5.1.sql.php), replace the app with the working
#            state, run `occ upgrade`, then `occ maintenance:repair` twice
#   bare     install the 5.5.1 release tarball WITHOUT seeding (a fresh 5.5.1 has no audit chain
#            head and only the legacy translation tables), then upgrade like `upgrade`
#   recent-<v>  for each version in RECENT_BASES (default "5.13.0 5.14.0 5.14.1 5.15.0 5.16.0 5.17.0 5.18.0 5.18.1 5.19.0 5.20.0 5.20.1"): install that release,
#            seed course videos, document rows, progress and read confirmations
#            (scripts/lifecycle-fixtures/recent-case.php), upgrade like `upgrade`, repair once, then
#            read them back through the app's mappers and RequiredDocumentService. The upgraded
#            schema must equal the fresh scenario's schema, and scripts/schema-drift.py must find no
#            breaking drift between them and the entities (the 5.14.1 lesson: SELECT * over a column
#            the entity does not know). The tarballs are the GitHub release assets (sha256 pinned
#            below); RECENT_TARBALL_DIR=<dir> takes <dir>/<v>/learning-<v>.tar.gz instead (the same
#            checksum must match). Nextcloud runs migrations only on a version change, so a base
#            that is not older than the working state fails `recent_<v>_version_jump` rather than
#            passing an upgrade that did nothing; PROBE_BUMP_VERSION=<x.y.z> sets that version in
#            the probe's copy of info.xml (never in the repository) to test unreleased migrations.
#            RECENT_BASES="" leaves the scenario out (the log says so).
# The fresh, upgrade and bare scenarios end with scripts/lifecycle-fixtures/check-working-state.php: audit chain head,
# a real compliance event, the expected chain position, and a translation round-trip.
# The LLM upgrade contract (plan Task C2) is checked by scripts/lifecycle-fixtures/check-llm-upgrade.php:
#   fresh    a repair run reports no AI migration failure; no ai_connection, AI `disabled`, and the
#            gateway refuses an admin test with category `disabled`
#   upgrade  the upgrade and both repair runs report no AI migration failure; the connection is
#            derived from the 5.5 keys (Gemini, gateway admin test begins), the Gemini key is
#            encrypted and readable and the plaintext is gone, the 2.0 consents get reason=version,
#            the seeded scheduled legacy task is gone in every status (cancelled is not enough) and
#            the running one is listed with the cleanup job registered, chat memory is empty, the
#            seeded allowlisted AI audit rows are redacted and the foreign one is not; the stored
#            state (digests) is identical after the upgrade and after each repair run; once the
#            running task has finished, the cleanup sweep deletes it.
#            `occ learning:audit:verify` passes over the 3 seeded chained events on 5.5.1 before the
#            upgrade and again after it, so the redaction cannot have touched the hash chain.
# These run before check-working-state.php, which writes audit rows and a pool of its own.
# The member repair step (CanonicalizeMemberUserIds, GitHub #35) is checked by
# scripts/lifecycle-fixtures/member-case.php: wrongly cased course members and pool shares are
# seeded (upgrade: on 5.5.1, before the upgrade; fresh: after `app:enable`, before the repair run)
# and must come out under the canonical uid, duplicates and owner rows removed, orphans and group
# shares untouched; in the upgrade scenario again after both repair runs (a rerun changes nothing).
# On a fresh install <install> runs while the tables are still empty, so there the checker can show
# the step's registration in the installed info.xml, and the repair run shows its effect.
# The app under test is app/ from the git index, or from PROBE_REF (e.g. to prove the checks go
# red on a release without the fix). The fixtures always come from the git index — never the
# working copy, so an unstaged fixture edit cannot validate a probe nobody reviewed.
# Every check prints `LIFECYCLE <engine> <check>=ok|fail`; the last line, printed only after
# cleanup, is `LIFECYCLE <engine> result=ok|fail`. Exit 0 only if every check passed and cleanup
# succeeded.
# PROBE_FAIL_AFTER=key_write is handed to the first `occ upgrade` of the upgrade scenario (the
# post-migration repair step of Task C1 runs inside it). That run must abort AND print
# `PROBE-INJECTED key_write`; without the marker the injection point was never reached and the
# check fails. The repair step aborts with an \Error (Nextcloud's Repair::run swallows every
# \Exception and would let the upgrade succeed), which leaves maintenance mode on; the probe
# switches it off as an admin would, and a second `occ upgrade` must then recover.
# Containers are named learning-gate-life-*-$$ and always removed (exit 3 if that fails).
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENGINE="${ENGINE:-pgsql}"
NC_IMAGE="${LIFECYCLE_NC_IMAGE:-nextcloud:33@sha256:df735d59202b74546ca4f935ec860d8397995a6e4e353926c4876547f6c6c4a5}"
PG_IMAGE="postgres:16-alpine@sha256:721873c34ceb9f8d8fc265984940dc982404c105f19ad51be9fdc5970a6080ea"
MY_IMAGE="mariadb:10.11@sha256:7f22313fc130a377a44999965bcb0a08dd5b21e8502824c1b864f792f9bc66ab"
OLD_URL="https://github.com/andremadstop/learning-nc/releases/download/v5.5.1/learning-5.5.1.tar.gz"
OLD_SHA256="b4a7ff9100d761375d74031bbce6000ea0da89ff851a2873f109cb0ef02d01ef"
OLD_VERSION="5.5.1"
RELEASE_SET=(appinfo css img js l10n lib templates data CHANGELOG.md LICENSE README.md)
# Recent releases for the recent-<v> scenario: GitHub release asset sha256 (= the signed tarball
# uploaded to the App Store). Add a release here once it is out.
declare -A RECENT_SHA256=(
	[5.13.0]=e3079cdfe9b97addd1a69fe0b05d3ee05a9900ea3d3eddabaa7647f02d8d181d
	[5.14.0]=20a168539d6f40c6e674204a49f44b6b88f511d26240781961ab0b2a4937f032
	[5.14.1]=559815b220a2d1702c77713484510b932baf05cae67295a1bbe5f514f1a3d71a
	[5.15.0]=04a387805f2278a2d6eeb5bf1f6d133866228ae6cc5c82c08afc2e66cf6ba34f
	[5.16.0]=e83c6afe6492442bade4b3e65f228ab2703e7e6180b3e62ff2bb4d3d6bc1cfea
	[5.17.0]=156fb6f93d32672fbf0af8e9610024c8a4eac08c540b1e5f2339de2fb15b225d
	[5.18.0]=98d096174e27cd99b041d46da91cffee54dbfaa1e7dc0d3a9a2c57c76e675e81
	[5.18.1]=685d60005bda9295879676732940a4550573304ed4a8bc011f0a9ed845bd059e
	[5.19.0]=fa3bee7af04c51521ef91f0c5af88d1eb99fbeef0704eefc19d060023c6cb4ef
	[5.20.0]=ed3d36df6e91aa70de1d9eedab5375839c76ff6c815b84cf9f4e1c06e53c301f
	[5.20.1]=80605a1487808995ee7e7b52930018133eda2cf2f84bfe4bcc326edb26455d7a
)
# Default bases: add a release only in the commit that raises info.xml past it; while info.xml
# still holds that version, recent_<v>_version_jump fails (see TESTING.md, "Before every release").
# A pinned release that is not yet a default base runs with RECENT_BASES="… <v>" PROBE_BUMP_VERSION=<next>.
RECENT_BASES="${RECENT_BASES-5.13.0 5.14.0 5.14.1 5.15.0 5.16.0 5.17.0 5.18.0 5.18.1 5.19.0 5.20.0 5.20.1}"
RECENT_TARBALL_DIR="${RECENT_TARBALL_DIR:-}"
PROBE_BUMP_VERSION="${PROBE_BUMP_VERSION:-}"
for v in $RECENT_BASES; do
	[ -n "${RECENT_SHA256[$v]:-}" ] || { echo "RECENT_BASES: no pinned sha256 for $v (known: ${!RECENT_SHA256[*]})" >&2; exit 2; }
done
case "$PROBE_BUMP_VERSION" in ''|[0-9]*.[0-9]*.[0-9]*) ;; *) echo "PROBE_BUMP_VERSION must look like x.y.z" >&2; exit 2 ;; esac

PROBE_FAIL_AFTER="${PROBE_FAIL_AFTER:-}"
case "$ENGINE" in pgsql|mysql) ;; *) echo "ENGINE must be pgsql or mysql" >&2; exit 2 ;; esac
case "$PROBE_FAIL_AFTER" in ''|key_write) ;; *) echo "PROBE_FAIL_AFTER must be empty or key_write" >&2; exit 2 ;; esac

WORK="$(mktemp -d "${TMPDIR:-/tmp}/learning-life.XXXXXX")"
SEEDER="$WORK/repo/scripts/lifecycle-fixtures/seed-5.5.1.sql.php"
CHECKER="$WORK/repo/scripts/lifecycle-fixtures/check-working-state.php"
LLM_CHECKER="$WORK/repo/scripts/lifecycle-fixtures/check-llm-upgrade.php"
MEMBER_CASE="$WORK/repo/scripts/lifecycle-fixtures/member-case.php"
RECENT_CASE="$WORK/repo/scripts/lifecycle-fixtures/recent-case.php"
DRIFT="$WORK/repo/scripts/schema-drift.py"
PASS="throwaway-$$-$RANDOM"   # dies with the containers; never leaves this machine
CREATED=()                     # "container:<name>" / "network:<name>", in creation order
FAILED=0

cleanup() {
	local rc=$? ok=1 i kind name
	for (( i=${#CREATED[@]}-1; i>=0; i-- )); do
		kind="${CREATED[i]%%:*}"; name="${CREATED[i]#*:}"
		if [ "$kind" = container ]; then docker rm -f -v "$name" >/dev/null
		else docker network rm "$name" >/dev/null; fi || { echo "lifecycle-probe: CLEANUP FAILED: removing $kind $name" >&2; ok=0; }
		# A failed inspect means "absent" only while the daemon answers; otherwise it proves nothing.
		if ! docker info >/dev/null 2>&1; then
			echo "lifecycle-probe: CLEANUP UNVERIFIED: docker daemon not reachable ($kind $name)" >&2; ok=0
		elif docker "$kind" inspect "$name" >/dev/null 2>&1; then
			echo "lifecycle-probe: CLEANUP FAILED: $kind $name still exists" >&2; ok=0
		fi
	done
	rm -r -- "$WORK" || { echo "lifecycle-probe: CLEANUP FAILED: $WORK" >&2; ok=0; }
	if [ "$ok" = 0 ]; then FAILED=1; [ "$rc" -ne 0 ] || rc=3; fi
	if [ "$FAILED" = 0 ] && [ "$rc" -eq 0 ]; then echo "LIFECYCLE $ENGINE result=ok"
	else echo "LIFECYCLE $ENGINE result=fail"; [ "$rc" -ne 0 ] || rc=1; fi
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
echo "==> fixtures from git index"
git -C "$REPO_ROOT" checkout-index -a --prefix="$WORK/repo/"
if [ -n "${PROBE_REF:-}" ]; then
	echo "==> app under test from $PROBE_REF"
	rm -r -- "$WORK/repo/app"
	git -C "$REPO_ROOT" archive "$PROBE_REF" app | tar -x -C "$WORK/repo"
else
	echo "==> app under test from git index"
fi
for f in "$SEEDER" "$CHECKER" "$LLM_CHECKER" "$MEMBER_CASE" "$RECENT_CASE" "$DRIFT"; do [ -f "$f" ] || { echo "fixture ${f#$WORK/repo/} is not in the git index" >&2; exit 2; }; done
mkdir -p "$WORK/new/learning"
for f in "${RELEASE_SET[@]}"; do
	[ -e "$WORK/repo/app/$f" ] || { echo "working state lacks app/$f" >&2; exit 2; }
	cp -a "$WORK/repo/app/$f" "$WORK/new/learning/"
done
rm -f -- "$WORK/new/learning/appinfo/signature.json"   # the unsigned working state, not a stale signature
if [ -n "$PROBE_BUMP_VERSION" ]; then
	# the probe's copy only: the first <version> is the app's (the AI provider list has others)
	sed -i "0,/<version>[^<]*<\/version>/s//<version>$PROBE_BUMP_VERSION<\/version>/" "$WORK/new/learning/appinfo/info.xml"
	echo "==> PROBE_BUMP_VERSION: working state installed as $PROBE_BUMP_VERSION (probe copy of info.xml only)"
fi
NEW_VERSION="$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$WORK/new/learning/appinfo/info.xml" | head -1)"

echo "==> $OLD_VERSION release tarball"
curl -fsSL -o "$WORK/old.tar.gz" "$OLD_URL"
echo "$OLD_SHA256  $WORK/old.tar.gz" | sha256sum -c --quiet -
mkdir -p "$WORK/old"; tar -xzf "$WORK/old.tar.gz" -C "$WORK/old"
[ -f "$WORK/old/learning/appinfo/info.xml" ] || { echo "tarball has no learning/appinfo/info.xml" >&2; exit 2; }

for v in $RECENT_BASES; do
	echo "==> $v release tarball${RECENT_TARBALL_DIR:+ from $RECENT_TARBALL_DIR}"
	if [ -n "$RECENT_TARBALL_DIR" ]; then
		cp -- "$RECENT_TARBALL_DIR/$v/learning-$v.tar.gz" "$WORK/base-$v.tar.gz"
	else
		curl -fsSL -o "$WORK/base-$v.tar.gz" "https://github.com/andremadstop/learning-nc/releases/download/v$v/learning-$v.tar.gz"
	fi
	echo "${RECENT_SHA256[$v]}  $WORK/base-$v.tar.gz" | sha256sum -c --quiet -
	mkdir -p "$WORK/base-$v"; tar -xzf "$WORK/base-$v.tar.gz" -C "$WORK/base-$v"
	[ -f "$WORK/base-$v/learning/appinfo/info.xml" ] || { echo "$v tarball has no learning/appinfo/info.xml" >&2; exit 2; }
done

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

# add_users — the accounts the member-case fixture refers to (seed() adds them on upgrade)
add_users() {
	local u
	for u in lc-user1 lc-user2 lc-user3; do
		docker exec -u www-data -w /var/www/html -e OC_PASS="$PASS" "$NC" \
			php occ user:add --password-from-env "$u" >/dev/null || return 1
	done
}

# member_case <seed|check> — wrongly cased members/shares in, canonical ones out (member-case.php)
member_case() {
	docker cp "$MEMBER_CASE" "$NC:/tmp/member-case.php" || return 1
	local out rc=0
	out="$(docker exec -u www-data "$NC" php /tmp/member-case.php "$1" 2>&1)" || rc=$?
	echo "$out"
	if [ "$1" = seed ]; then
		[ "$rc" -eq 0 ] && grep -q '^SEED member_rows=5 ' <<<"$out" && grep -q '^SEED share_rows=3 ' <<<"$out"
	else
		[ "$rc" -eq 0 ] && grep -q '^CHECK result=ok$' <<<"$out"
	fi
}

# working_state <expected_last_seq> — the fresh-install regressions of 5.5.2 are gone
working_state() {
	docker cp "$CHECKER" "$NC:/tmp/check.php" || return 1
	local out rc=0
	out="$(docker exec -u www-data "$NC" php /tmp/check.php "$1" 2>&1)" || rc=$?
	echo "$out"
	[ "$rc" -eq 0 ] && grep -q '^CHECK result=ok$' <<<"$out"
}

# llm_contract <upgraded|fresh> — the LLM upgrade contract of check-llm-upgrade.php
llm_contract() {
	docker cp "$LLM_CHECKER" "$NC:/tmp/check-llm-upgrade.php" || return 1
	local out rc=0
	out="$(docker exec -u www-data "$NC" php /tmp/check-llm-upgrade.php "$1" 2>&1)" || rc=$?
	echo "$out"
	[ "$rc" -eq 0 ] && grep -q '^CHECK result=ok$' <<<"$out"
}

# llm_snapshot <name> — store the digests of everything the AI migration may touch in $WORK
llm_snapshot() {
	docker cp "$LLM_CHECKER" "$NC:/tmp/check-llm-upgrade.php" || return 1
	local out rc=0
	out="$(docker exec -u www-data "$NC" php /tmp/check-llm-upgrade.php snapshot 2>&1)" || rc=$?
	echo "$out"
	[ "$rc" -eq 0 ] && grep -q '^STATE consent=' <<<"$out" && ! grep -q '^STATE error' <<<"$out" &&
		printf '%s\n' "$out" >"$WORK/state-$1"
}

# same_state <before> <after> — a repeated migration run changed nothing
same_state() {
	[ -s "$WORK/state-$1" ] && [ -s "$WORK/state-$2" ] || { echo "snapshot $1 or $2 missing"; return 1; }
	diff "$WORK/state-$1" "$WORK/state-$2"
}

# audit_verify <events> — `occ learning:audit:verify` passes and walked exactly <events> chained
# events, so an empty walk cannot pass
audit_verify() {
	local out rc=0
	out="$(occ learning:audit:verify --json 2>/dev/null)" || rc=$?
	echo "$out" | grep -E '"(ok|events_verified)"|failures' || true
	echo "audit:verify exit code: $rc (want 0, events_verified $1)"
	[ "$rc" -eq 0 ] && grep -q '"ok": true' <<<"$out" && grep -Eq "\"events_verified\": $1," <<<"$out"
}

# occ_clean <args...> — run occ; it must succeed AND no AI migration part may report a failure.
# MigrateLlmConfiguration turns a failing part into a warning, which leaves the exit code at 0.
occ_clean() {
	local out rc=0
	out="$(occ "$@" 2>&1)" || rc=$?
	echo "$out" | tail -n 5
	echo "occ $* exit code: $rc"
	# every warning MigrateLlmConfiguration and RedactLegacyAiAudit can give
	if grep -E 'AI configuration migration: .* failed|stays in plaintext|were not kept|AI audit redaction stopped' <<<"$out"; then
		echo "occ $*: the AI migration reported a failure"
		return 1
	fi
	[ "$rc" -eq 0 ]
}

# The injected upgrade must abort at the injection point: non-zero exit AND the marker the app
# prints when it gets there. Either one missing means the interruption was never exercised.
upgrade_injected() {
	local out rc=0
	out="$(docker exec -u www-data -w /var/www/html -e PROBE_FAIL_AFTER="$PROBE_FAIL_AFTER" "$NC" \
		php occ upgrade 2>&1)" || rc=$?
	echo "$out" | tail -n 5
	echo "injected upgrade (PROBE_FAIL_AFTER=$PROBE_FAIL_AFTER) exit code: $rc"
	[ "$rc" -ne 0 ] && grep -q "PROBE-INJECTED $PROBE_FAIL_AFTER" <<<"$out"
}

# recent_case <seed|check> — recent-release course data in, read back through the app (recent-case.php)
recent_case() {
	docker cp "$RECENT_CASE" "$NC:/tmp/recent-case.php" || return 1
	local out rc=0
	out="$(docker exec -u www-data "$NC" php /tmp/recent-case.php "$1" 2>&1)" || rc=$?
	echo "$out"
	if [ "$1" = seed ]; then [ "$rc" -eq 0 ] && grep -q '^SEED kind=' <<<"$out"
	else [ "$rc" -eq 0 ] && grep -q '^CHECK result=ok$' <<<"$out"; fi
}

# schema_dump <name> — the learning tables' `table|column` lines of the current instance
schema_dump() {
	if [ "$ENGINE" = pgsql ]; then
		docker exec "$DB" psql -U nc -d nextcloud -tA -F '|' -c "select table_name, column_name from information_schema.columns
			where table_schema = current_schema() and table_name like 'oc\\_learning%' order by 1, 2" >"$WORK/schema-$1.txt" || return 1
	else
		docker exec "$DB" mariadb -unc -p"$PASS" -h 127.0.0.1 -N -B -e "select table_name, column_name from information_schema.columns
			where table_schema = DATABASE() and table_name like 'oc\\_learning%' order by 1, 2" nextcloud | tr '\t' '|' >"$WORK/schema-$1.txt" || return 1
	fi
	echo "schema $1: $(wc -l <"$WORK/schema-$1.txt") columns"
	[ -s "$WORK/schema-$1.txt" ]
}

# same_schema <a> <b> — two dumps list the same tables and columns
same_schema() { diff "$WORK/schema-$1.txt" "$WORK/schema-$2.txt"; }

# no_drift <live> <fresh> — schema-drift.py finds no breaking drift against the app under test
no_drift() { python3 -I "$DRIFT" --live "$WORK/schema-$1.txt" --fresh "$WORK/schema-$2.txt" --lib "$WORK/repo/app/lib"; }

# newer <a> <b> — version a is strictly newer than b
newer() {
	echo "working state $1, base $2"
	[ "$1" != "$2" ] && [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | tail -1)" = "$1" ]
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
check fresh_users add_users
check fresh_member_seed member_case seed
check fresh_repair occ_clean maintenance:repair
check fresh_member_case member_case check
check fresh_llm llm_contract fresh
check fresh_working_state working_state 1
check fresh_schema_dump schema_dump fresh

# --- scenario: upgrade 5.5.1 -> working state -------------------------------------------------
echo "==> [$ENGINE] install $OLD_VERSION, seed, upgrade to $NEW_VERSION"
check upgrade_setup new_instance upgrade
check upgrade_install_old install_nc "$WORK/old/learning"
check upgrade_enable_old occ app:enable learning
check upgrade_old_version version_is "$OLD_VERSION"
check seed seed
check upgrade_audit_verify_old audit_verify 3
check upgrade_member_seed member_case seed
check upgrade_swap swap_to_working_state
if [ -n "$PROBE_FAIL_AFTER" ]; then
	check upgrade_occ_upgrade_injected upgrade_injected
	# An aborted `occ upgrade` leaves maintenance mode on, and the recovery run keeps it on because
	# it was already on. Nextcloud's documented recovery: switch it off, run the upgrade again.
	check upgrade_maintenance_off occ maintenance:mode --off
fi
check upgrade_occ_upgrade occ_clean upgrade
check upgrade_new_version version_is "$NEW_VERSION"
check upgrade_member_case member_case check
check upgrade_llm llm_contract upgraded
check upgrade_state_0 llm_snapshot 0
check upgrade_repair_1 occ_clean maintenance:repair
check upgrade_state_1 llm_snapshot 1
check upgrade_repair_1_no_change same_state 0 1
check upgrade_repair_2 occ_clean maintenance:repair
check upgrade_state_2 llm_snapshot 2
check upgrade_repair_2_no_change same_state 1 2
check upgrade_member_case_after_repair member_case check
check upgrade_llm_after_repair llm_contract upgraded
check upgrade_audit_verify_new audit_verify 3
# changes state, so after the no-change comparisons: the running task finishes, the sweep deletes it
check upgrade_legacy_sweep llm_contract sweep
check upgrade_enabled app_enabled
# 3 chained rows seeded + 1 from the check: the existing head was kept, not re-seeded
check upgrade_working_state working_state 4

# --- scenario: bare 5.5.1 install (no seed) -> working state ----------------------------------
echo "==> [$ENGINE] install $OLD_VERSION without seeding, upgrade to $NEW_VERSION"
check bare_setup new_instance bare
check bare_install_old install_nc "$WORK/old/learning"
check bare_enable_old occ app:enable learning
check bare_swap swap_to_working_state
check bare_occ_upgrade occ_clean upgrade
check bare_new_version version_is "$NEW_VERSION"
check bare_repair occ_clean maintenance:repair
check bare_working_state working_state 1

# --- scenario: recent release (5.13.x / 5.14.x) with course data -> working state ------------
[ -n "$RECENT_BASES" ] || echo "==> [$ENGINE] recent-release scenario left out (RECENT_BASES is empty)"
for v in $RECENT_BASES; do
	echo "==> [$ENGINE] install $v, seed course data, upgrade to $NEW_VERSION"
	s="recent_${v//./_}"
	check "${s}_version_jump" newer "$NEW_VERSION" "$v"
	check "${s}_setup" new_instance "recent-${v//./-}"
	check "${s}_install_old" install_nc "$WORK/base-$v/learning"
	check "${s}_enable_old" occ app:enable learning
	check "${s}_old_version" version_is "$v"
	check "${s}_users" add_users
	check "${s}_seed" recent_case seed
	check "${s}_swap" swap_to_working_state
	check "${s}_occ_upgrade" occ_clean upgrade
	check "${s}_new_version" version_is "$NEW_VERSION"
	check "${s}_repair" occ_clean maintenance:repair
	check "${s}_course_data" recent_case check
	check "${s}_schema_dump" schema_dump "$s"
	check "${s}_schema_eq_fresh" same_schema fresh "$s"
	check "${s}_no_drift" no_drift "$s" fresh
	check "${s}_enabled" app_enabled
done

[ "$FAILED" = 0 ] || exit 1   # the verdict line comes from cleanup(), after removal

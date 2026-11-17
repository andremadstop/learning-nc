#!/usr/bin/env bash
# schema-drift.sh — compare a live instance's learning tables with a fresh schema and the entities.
#
# Usage: scripts/schema-drift.sh <live-file> [--fresh <file> | --fresh-ref <git-ref>] [--lib-ref <git-ref>]
#        python3 scripts/schema-drift.py --list    # every mapper method that selects '*', per table
#
# Read-only by design: the live side is a text file of `table|column` lines that YOU produce on the
# instance with one of the queries below. This script never connects to a database.
#
#   PostgreSQL (replace oc_ with the instance's dbtableprefix, see config.php):
#     psql -U <dbuser> -d <dbname> -tA -F '|' -c "select table_name, column_name
#       from information_schema.columns where table_schema = current_schema()
#       and table_name like 'oc\_learning\_%' order by 1, 2" > live-schema.txt
#
#   MySQL / MariaDB:
#     mysql -u <dbuser> -p -N -B -e "select table_name, column_name
#       from information_schema.columns where table_schema = DATABASE()
#       and table_name like 'oc\_learning\_%' order by 1, 2" <dbname> | tr '\t' '|' > live-schema.txt
#
#   With the database in a container, prefix the command with `docker exec <db-container>`
#   (for psql: `docker exec <db-container> psql …`; the redirect stays on the host side).
#
# The fresh side is scripts/schema-dump.sh output: by default this script runs it on the git index
# (Docker, ~2 min); --fresh <file> reuses a dump, --fresh-ref <ref> dumps a commit instead.
# The entities come from app/lib of the git index, or of <ref> with --lib-ref (never the working
# copy, like every other gate). Comparison and findings: scripts/schema-drift.py.
#
# Exit 0 = no breaking drift, 1 = breaking drift (a live column without entity property, a missing
# table or column), 2 = usage or input error, 3 = cleanup failed.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
GIT_ROOT="${GATE_GIT_ROOT:-$REPO_ROOT}"

usage() { echo "usage: $0 <live-file> [--fresh <file> | --fresh-ref <git-ref>] [--lib-ref <git-ref>]" >&2; exit 2; }

LIVE="${1:-}"; [ -n "$LIVE" ] || usage; shift
FRESH=""; FRESH_REF=""; LIB_REF=""
while [ $# -gt 0 ]; do
	case "$1" in
		--fresh)     FRESH="${2:?--fresh needs a file}"; shift 2 ;;
		--fresh-ref) FRESH_REF="${2:?--fresh-ref needs a git ref}"; shift 2 ;;
		--lib-ref)   LIB_REF="${2:?--lib-ref needs a git ref}"; shift 2 ;;
		*) usage ;;
	esac
done
[ -f "$LIVE" ] && [ -s "$LIVE" ] || { echo "schema-drift: live file missing or empty: $LIVE" >&2; exit 2; }
[ -z "$FRESH" ] || [ -z "$FRESH_REF" ] || { echo "schema-drift: --fresh and --fresh-ref exclude each other" >&2; exit 2; }

WORK="$(mktemp -d "${TMPDIR:-/tmp}/learning-drift.XXXXXX")"
cleanup() {
	local rc=$?
	if ! rm -r -- "$WORK"; then echo "schema-drift: CLEANUP FAILED: $WORK" >&2; [ "$rc" -ne 0 ] || rc=3; fi
	exit "$rc"
}
trap cleanup EXIT

if [ -n "$LIB_REF" ]; then
	git -C "$GIT_ROOT" archive "$LIB_REF" app/lib | tar -x -C "$WORK"
else
	git -C "$GIT_ROOT" checkout-index -a --prefix="$WORK/"
fi
[ -d "$WORK/app/lib/Db" ] || { echo "schema-drift: snapshot has no app/lib/Db" >&2; exit 2; }

if [ -z "$FRESH" ]; then
	FRESH="$WORK/fresh-schema.txt"
	if [ -n "$FRESH_REF" ]; then "$REPO_ROOT/scripts/schema-dump.sh" "$FRESH" --ref "$FRESH_REF"
	else "$REPO_ROOT/scripts/schema-dump.sh" "$FRESH" --index; fi
fi

rc=0
python3 -I "$REPO_ROOT/scripts/schema-drift.py" --live "$LIVE" --fresh "$FRESH" --lib "$WORK/app/lib" || rc=$?
exit "$rc"

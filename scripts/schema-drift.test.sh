#!/usr/bin/env bash
# Self-test for scripts/schema-drift.py (the comparison behind scripts/schema-drift.sh).
#
# Builds a throwaway lib/Db with two mappers and runs the comparison on hand-written schema files:
#   - the 5.14.1 case: live learning_pools.visibility, entity without $visibility -> exit 1
#     (and exit 0 once the entity has the property, as in 5.14.1)
#   - a missing column, a missing entity table, a missing table other code uses -> exit 1
#   - a missing legacy table nobody uses, extra tables/columns, a renamed table -> notes, exit 0
#   - typed properties, data_b64 -> $dataB64, another table prefix, tab separators
#   - missing or empty input -> exit 2
# and parses the real app/lib, which must yield 40+ entities and SELECT * methods (non-vacuity).
#
# Usage: bash scripts/schema-drift.test.sh      (no Docker, no database; < 2 s)
set -u

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PY="$ROOT/scripts/schema-drift.py"
T="$(mktemp -d "${TMPDIR:-/tmp}/schema-drift-test.XXXXXX")"
trap 'rm -r -- "$T"' EXIT
PASS=0; FAIL=0

# expect <name> <exit> <grep-pattern|-> <args...>
expect() {
	local name="$1" want="$2" pat="$3"; shift 3
	local out rc=0
	out="$(python3 -I "$PY" "$@" 2>&1)" || rc=$?
	if [ "$rc" = "$want" ] && { [ "$pat" = - ] || grep -qE -- "$pat" <<<"$out"; }; then
		echo "  PASS: $name (exit=$rc)"; PASS=$((PASS + 1))
	else
		echo "  FAIL: $name (exit=$rc, want $want${pat:+, pattern '$pat'})"; echo "$out" | sed 's/^/      /'; FAIL=$((FAIL + 1))
	fi
}

mklib() {   # mklib <dir> <pool-has-visibility 0|1>
	mkdir -p "$1/Db" "$1/Service" "$1/Migration" "$1/Command"
	cat >"$1/Db/PoolMapper.php" <<'PHP'
<?php
class PoolMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_pools', Pool::class);
    }
    public function find(int $id): Pool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName());
        return $this->findEntity($qb);
    }
    public function names(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('name')->from($this->getTableName());
        return [];
    }
}
PHP
	{
		echo '<?php'
		echo 'class Pool extends Entity {'
		echo '    protected $userId;'
		echo '    protected ?string $name = null;'
		echo '    public int $dataB64 = 0;'
		[ "$2" = 1 ] && echo '    protected $visibility;'
		echo '    public static $notAColumn;'
		echo '    protected function helper(): void {}'
		echo '}'
	} >"$1/Db/Pool.php"
	cat >"$1/Db/ShareMapper.php" <<'PHP'
<?php
class ShareMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, "learning_pool_shares", \OCA\Learning\Db\Share::class);
    }
}
PHP
	printf '<?php\nclass Share extends Entity {\n    protected $poolId;\n}\n' >"$1/Db/Share.php"
	printf '<?php\n$qb->select("x")->from(\x27learning_doc_confirms\x27);\n' >"$1/Service/DocService.php"
	printf '<?php\n$schema->dropTable(\x27learning_q_translations\x27);\n' >"$1/Migration/Version1.php"
	printf '<?php\n$tables = [\x27learning_q_translations\x27, \x27learning_doc_confirms\x27];\n' >"$1/Command/UninstallCommand.php"
}
mklib "$T/old" 0
mklib "$T/new" 1

cat >"$T/fresh.txt" <<'TXT'
oc_learning_pools|id
oc_learning_pools|user_id
oc_learning_pools|name
oc_learning_pools|data_b64
oc_learning_pool_shares|id
oc_learning_pool_shares|pool_id
oc_learning_doc_confirms|id
oc_learning_q_translations|id
oc_learning_q_translations|text
oc_learning_q_translations|lang
TXT
grep -v visibility "$T/fresh.txt" >"$T/live-clean.txt"
{ cat "$T/fresh.txt"; echo 'oc_learning_pools|visibility'; } >"$T/live-legacy.txt"

echo "schema-drift self-test"
expect "fresh == live, no drift" 0 'no breaking drift' --live "$T/live-clean.txt" --fresh "$T/fresh.txt" --lib "$T/new"
expect "5.14.1: legacy column, entity without property" 1 'COLUMN WITHOUT PROPERTY: learning_pools.visibility -> Pool::\$visibility missing \(live only; SELECT \* in PoolMapper: find\)' \
	--live "$T/live-legacy.txt" --fresh "$T/fresh.txt" --lib "$T/old"
expect "5.14.1 fixed: legacy column read and ignored" 0 'EXTRA COLUMN: learning_pools.visibility \(live only, entity Pool has \$visibility\)' \
	--live "$T/live-legacy.txt" --fresh "$T/fresh.txt" --lib "$T/new"
expect "property without a column is only a note" 0 'PROPERTY WITHOUT COLUMN: Pool::\$visibility' --live "$T/live-clean.txt" --fresh "$T/fresh.txt" --lib "$T/new"

grep -v 'pools|data_b64' "$T/live-clean.txt" >"$T/live-nocol.txt"
expect "missing column (migration did not run)" 1 'MISSING COLUMN: learning_pools.data_b64' --live "$T/live-nocol.txt" --fresh "$T/fresh.txt" --lib "$T/new"
grep -v 'pool_shares' "$T/live-clean.txt" >"$T/live-noent.txt"
expect "missing entity table" 1 'MISSING TABLE \(entity Share\): learning_pool_shares' --live "$T/live-noent.txt" --fresh "$T/fresh.txt" --lib "$T/new"
grep -v 'doc_confirms' "$T/live-clean.txt" >"$T/live-noused.txt"
expect "missing table other code uses" 1 'MISSING TABLE \(used in Service/DocService.php\): learning_doc_confirms' --live "$T/live-noused.txt" --fresh "$T/fresh.txt" --lib "$T/new"
grep -v 'q_translations' "$T/live-clean.txt" >"$T/live-renamed.txt"
printf 'oc_learning_qst_translations|id\noc_learning_qst_translations|text\noc_learning_qst_translations|lang\n' >>"$T/live-renamed.txt"
expect "legacy table gone, renamed one live: notes only" 0 'NAME\? table learning_qst_translations \(live\) looks like learning_q_translations' \
	--live "$T/live-renamed.txt" --fresh "$T/fresh.txt" --lib "$T/new"
expect "unused missing table is a note" 0 'MISSING TABLE: learning_q_translations \(fresh only' --live "$T/live-renamed.txt" --fresh "$T/fresh.txt" --lib "$T/new"
{ cat "$T/live-clean.txt"; echo 'oc_learning_pool_shares|role'; } >"$T/live-extra.txt"
expect "extra column on an entity table without property" 1 'COLUMN WITHOUT PROPERTY: learning_pool_shares.role -> Share::\$role missing \(live only; SELECT \* in ShareMapper: none\)' \
	--live "$T/live-extra.txt" --fresh "$T/fresh.txt" --lib "$T/new"
sed -e 's/^oc_/nc_/' -e 's/|/\t/' "$T/live-legacy.txt" | tr 'a-z' 'A-Z' >"$T/live-prefix.txt"
expect "other prefix, tabs, upper case" 0 'EXTRA COLUMN: learning_pools.visibility' --live "$T/live-prefix.txt" --fresh "$T/fresh.txt" --lib "$T/new"
expect "missing live file" 2 'live file missing' --live "$T/nope.txt" --fresh "$T/fresh.txt" --lib "$T/new"
: >"$T/empty.txt"
expect "empty live file" 2 'no learning_\* table' --live "$T/empty.txt" --fresh "$T/fresh.txt" --lib "$T/new"
expect "lib without Db" 2 'no Db directory' --live "$T/live-clean.txt" --fresh "$T/fresh.txt" --lib "$T"

# Non-vacuity on the real tree: the mapper and property parsers find the app's entities.
out="$(python3 -I "$PY" --live "$T/live-clean.txt" --fresh "$T/fresh.txt" --lib "$ROOT/app/lib" 2>&1 | head -1)"
ents="$(sed -n 's/.* \([0-9]*\) entities, \([0-9]*\) mapper methods.*/\1/p' <<<"$out")"
stars="$(sed -n 's/.* \([0-9]*\) entities, \([0-9]*\) mapper methods.*/\2/p' <<<"$out")"
if [ "${ents:-0}" -ge 40 ] && [ "${stars:-0}" -ge 40 ]; then
	echo "  PASS: real app/lib parsed ($ents entities, $stars SELECT * methods)"; PASS=$((PASS + 1))
else
	echo "  FAIL: real app/lib parsed too little: $out"; FAIL=$((FAIL + 1))
fi

echo "schema-drift self-test: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]

# .githooks/lib-snapshot.sh — shared by pre-commit and pre-push (sourced, not a hook).
#
# Every gate runs against a clean snapshot of what is being committed/pushed, never the
# working copy: an uncommitted fix must not make a broken commit look green.
#
#   make_snapshot <dir> --index        tree of the git index (what `git commit` records)
#   make_snapshot <dir> --ref <sha>    tree of a commit (what `git push` sends)
#
# Each gate_* function takes the snapshot dir, prints one status line and returns
# non-zero on failure. Callers collect the results; nothing here relies on `set -e`.

GATE_RED='\033[0;31m'
GATE_GREEN='\033[0;32m'
GATE_YELLOW='\033[0;33m'
GATE_NC='\033[0m'

gate_ok()   { echo -e "${GATE_GREEN}OK${1:+ ($1)}${GATE_NC}"; }
gate_fail() { echo -e "${GATE_RED}FAIL${1:+ — $1}${GATE_NC}"; }

# make_snapshot <dir> <--index | --ref <sha>> — returns non-zero if anything went wrong.
make_snapshot() {
	local dir="$1" src="$2" ref="${3:-}" root
	root="$(git rev-parse --show-toplevel)" || return 1
	mkdir -p -- "$dir" || return 1
	case "$src" in
		--index)
			git -C "$root" checkout-index -a --prefix="$dir/" || { echo "snapshot: git checkout-index failed" >&2; return 1; } ;;
		--ref)
			[ -n "$ref" ] || { echo "snapshot: --ref needs a sha" >&2; return 1; }
			# Both sides of the pipe must succeed: a failed archive would leave an empty tree.
			( set -o pipefail; git -C "$root" archive "$ref" | tar -x -C "$dir" ) \
				|| { echo "snapshot: git archive $ref failed" >&2; return 1; } ;;
		*) echo "snapshot: unknown source $src" >&2; return 1 ;;
	esac
	local must
	for must in app/appinfo/info.xml app/lib app/src app/package.json app/package-lock.json scripts/check-i18n-parity.sh; do
		[ -e "$dir/$must" ] || { echo "snapshot: $must missing" >&2; return 1; }
	done
	# JS dependencies come from the working copy — only valid if the lockfiles match.
	local lock
	for lock in app/package-lock.json package-lock.json; do
		[ -f "$dir/$lock" ] || continue
		cmp -s -- "$dir/$lock" "$root/$lock" \
			|| { echo "snapshot: $lock differs from the working copy — run npm ci on that state first" >&2; return 1; }
	done
	[ -d "$root/app/node_modules" ] || { echo "snapshot: app/node_modules missing — run npm ci in app/" >&2; return 1; }
	ln -s -- "$root/app/node_modules" "$dir/app/node_modules" || return 1
	if [ -d "$root/node_modules" ]; then ln -s -- "$root/node_modules" "$dir/node_modules" || return 1; fi
	# check-forbidden-names.sh finds its root with `git rev-parse --show-toplevel`.
	# Unset the hook's GIT_* variables so this touches only the snapshot, never the real repo.
	env -u GIT_DIR -u GIT_INDEX_FILE -u GIT_WORK_TREE git -C "$dir" init -q || return 1
}

gate_security() {
	local dir="$1" hits
	echo -n "Security scan... "
	[ -d "$dir/app/lib" ] && [ -d "$dir/app/src" ] || { gate_fail "app/lib or app/src missing"; return 1; }
	# grep exits 1 for "no match", which is the passing case; anything above 1 is an error
	# (unreadable file, bad pattern) and must fail the gate instead of reading as "clean".
	local raw rc=0 filter_rc=0
	raw=$(grep -rn 'api_key.*=.*["'"'"']sk-\|password\s*=\s*["'"'"'][A-Za-z0-9]' --include="*.php" --include="*.js" --include="*.vue" "$dir/app/lib/" "$dir/app/src/") || rc=$?
	if [ "$rc" -gt 1 ]; then gate_fail "grep failed (exit $rc)"; return 1; fi
	hits=$(grep -v 'getenv\|config\|Config\|IConfig\|test\|example\|\.env\|password_hash\|passwordField\|password_confirm\|password_reset\|PASSWORD' <<<"$raw") || filter_rc=$?
	if [ "$filter_rc" -gt 1 ]; then gate_fail "grep filter failed (exit $filter_rc)"; return 1; fi
	if [ -n "$hits" ]; then gate_fail "possibly hardcoded secrets:"; echo "${hits//$dir\//}"; return 1; fi
	gate_ok
}

gate_eslint() {
	local dir="$1" out
	echo -n "ESLint... "
	if out=$(cd "$dir/app" && npx eslint --ext .js,.vue src/ --quiet 2>&1); then gate_ok "0 errors"; return 0; fi
	gate_fail; echo "$out" | head -15; return 1
}

gate_vitest() {
	local dir="$1" out rc=0
	echo -n "Vitest... "
	# Exit code decides, not the text: 'Tests 1 failed | 1220 passed' contains "passed".
	out=$(cd "$dir/app" && npm run --silent test 2>&1) || rc=$?
	if [ "$rc" -eq 0 ]; then gate_ok "$(echo "$out" | sed 's/\x1b\[[0-9;]*m//g' | grep -oE 'Tests +[0-9]+ passed' | tail -1)"; return 0; fi
	gate_fail "exit $rc"; echo "$out" | tail -12; return 1
}

gate_i18n() {
	local dir="$1" out
	echo -n "i18n (parity, js sync, placeholders, coverage, VirtuProf)... "
	if out=$(bash "$dir/scripts/check-i18n-parity.sh" 2>&1); then gate_ok; return 0; fi
	gate_fail; echo "$out" | tail -20; return 1
}

gate_forbidden_names() {
	local dir="$1" out
	echo -n "Forbidden names... "
	if out=$(cd "$dir" && env -u GIT_DIR -u GIT_INDEX_FILE -u GIT_WORK_TREE bash scripts/check-forbidden-names.sh 2>&1); then gate_ok; return 0; fi
	gate_fail; echo "$out" | head -20; return 1
}

# gate_php <--index | --ref <sha>> — PHPStan + PHPUnit in a throwaway container.
gate_php() {
	local root out rc=0
	root="$(git rev-parse --show-toplevel)" || return 1
	echo -n "PHPStan + PHPUnit (container)... "
	out=$("$root/scripts/gate-container.sh" all "$@" 2>&1) || rc=$?
	if [ "$rc" -eq 0 ]; then gate_ok "$(echo "$out" | tail -1)"; return 0; fi
	gate_fail "exit $rc"
	echo "$out" | grep -E "^ *[0-9]+ +|^ *Line|\[ERROR\]|^[0-9]+\)|^(FAILURES|ERRORS)!|^Tests:|GATE-RESULT|CLEANUP|gate-container:" | head -20
	return 1
}

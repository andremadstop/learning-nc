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
	# The gate scripts come from the snapshot too (see gate_php): a commit without them cannot be
	# checked by these hooks.
	for must in app/appinfo/info.xml app/lib app/src app/package.json app/package-lock.json scripts/check-i18n-parity.sh \
		scripts/gate-container.sh scripts/check-node-modules.py; do
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
	# Same lockfile is not enough: node_modules may still hold an earlier checkout's packages.
	python3 "$dir/scripts/check-node-modules.py" "$dir/app/package-lock.json" "$root/app/node_modules" || return 1
	if [ -f "$dir/package-lock.json" ] && [ -d "$root/node_modules" ]; then
		python3 "$dir/scripts/check-node-modules.py" "$dir/package-lock.json" "$root/node_modules" || return 1
	fi
	ln -s -- "$root/app/node_modules" "$dir/app/node_modules" || return 1
	if [ -d "$root/node_modules" ]; then ln -s -- "$root/node_modules" "$dir/node_modules" || return 1; fi
	# check-forbidden-names.sh finds its root with `git rev-parse --show-toplevel`.
	# Unset the hook's GIT_* variables so this touches only the snapshot, never the real repo.
	env -u GIT_DIR -u GIT_INDEX_FILE -u GIT_WORK_TREE git -C "$dir" init -q || return 1
}

gate_security() {
	local dir="$1" raw rc=0 hits
	echo -n "Security scan... "
	[ -d "$dir/app/lib" ] && [ -d "$dir/app/src" ] || { gate_fail "app/lib or app/src missing"; return 1; }
	# Run from the snapshot root so paths are relative: the allow-list below must only ever see
	# the matched source line, never a path (a TMPDIR containing "test" would hide everything).
	# grep exits 1 for "no match" (the passing case); above 1 is an error and fails the gate.
	raw=$(cd "$dir" && grep -rn 'api_key.*=.*["'"'"']sk-\|password\s*=\s*["'"'"'][A-Za-z0-9]' \
		--include="*.php" --include="*.js" --include="*.vue" app/lib/ app/src/) || rc=$?
	if [ "$rc" -gt 1 ]; then gate_fail "grep failed (exit $rc)"; return 1; fi
	# path:line:content -> keep "path:line" where the CONTENT is not an allowed pattern.
	hits=$(printf '%s\n' "$raw" | awk -F: 'NF >= 3 {
		content = $0; sub(/^[^:]*:[^:]*:/, "", content)
		if (content !~ /getenv|config|Config|IConfig|test|example|\.env|password_hash|passwordField|password_confirm|password_reset|PASSWORD/) print $1 ":" $2
	}') || { gate_fail "filter failed"; return 1; }
	# Only file:line — printing the line would copy a real credential into terminal and hook logs.
	if [ -n "$hits" ]; then gate_fail "possibly hardcoded secrets (content not shown):"; echo "$hits"; return 1; fi
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
	# Exit 0 alone is not proof: a runner that finds no tests, or a script that does nothing,
	# also exits 0. Require vitest's own summary with a positive count and nothing failed.
	local plain summary
	plain=$(printf '%s\n' "$out" | sed 's/\x1b\[[0-9;]*m//g')
	summary=$(printf '%s\n' "$plain" | grep -E '^ *Tests +' | tail -1)
	if [ "$rc" -eq 0 ] && grep -qE 'Tests +[1-9][0-9]* passed' <<<"$summary" && ! grep -q 'failed' <<<"$summary"; then
		gate_ok "$(grep -oE 'Tests +[0-9]+ passed' <<<"$summary")"; return 0
	fi
	gate_fail "exit $rc, summary: ${summary:-none}"; echo "$out" | tail -12; return 1
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

# gate_php <snapshot-dir> <--index | --ref <sha>> — PHPStan + PHPUnit in a throwaway container.
# The gate script runs from the snapshot, not the working copy: an unstaged edit that makes it
# exit 0 must not let the commit through.
gate_php() {
	local snap="$1" root out rc=0; shift
	root="$(git rev-parse --show-toplevel)" || return 1
	echo -n "PHPStan + PHPUnit (container)... "
	out=$(GATE_GIT_ROOT="$root" "$snap/scripts/gate-container.sh" all "$@" 2>&1) || rc=$?
	if [ "$rc" -eq 0 ]; then gate_ok "$(echo "$out" | tail -1)"; return 0; fi
	gate_fail "exit $rc"
	echo "$out" | grep -E "^ *[0-9]+ +|^ *Line|\[ERROR\]|^[0-9]+\)|^(FAILURES|ERRORS)!|^Tests:|GATE-RESULT|CLEANUP|gate-container:" | head -20
	return 1
}

#!/usr/bin/env bash
# route-security-inventory.sh — the security inventory of every controller method at a git ref
# (or the index), as Nextcloud's middlewares see it; see scripts/route-security-inventory.php.
#
# Usage: scripts/route-security-inventory.sh [--index | --ref <git-ref>] > inventory.json
#        scripts/route-security-inventory.sh --compare <old-ref> [<new-ref>|--index]
# --compare exits 1 and prints the diff when the two inventories differ (any route that changed
# public, CSRF, admin, brute-force or rate-limit behaviour). The scan logic is always taken from
# the current working copy, so an old tree without the helper can be scanned too.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
IMAGE="${GATE_IMAGE:-nextcloud:33@sha256:df735d59202b74546ca4f935ec860d8397995a6e4e353926c4876547f6c6c4a5}"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/learning-routesec.XXXXXX")"
trap 'rm -r -- "$WORK"' EXIT

# tree <name> <--index|ref> — app/ of that state in $WORK/<name>
tree() {
	mkdir -p "$WORK/$1"
	if [ "$2" = --index ]; then
		git -C "$REPO_ROOT" checkout-index -a --prefix="$WORK/$1/"
	else
		git -C "$REPO_ROOT" archive "$2" app | tar -x -C "$WORK/$1"
	fi
}

# scan <name> — inventory JSON of $WORK/<name>
scan() {
	docker run --rm --network none --entrypoint php \
		-v "$WORK/$1/app:/scan:ro" \
		-v "$REPO_ROOT/scripts/route-security-inventory.php:/inv.php:ro" \
		-v "$REPO_ROOT/app/tests/Support/RouteSecurityInventory.php:/helper.php:ro" \
		"$IMAGE" /inv.php /scan /usr/src/nextcloud/lib/public /helper.php
}

case "${1:---index}" in
	--index) tree cur --index; scan cur ;;
	--ref) tree cur "${2:?--ref needs a git ref}"; scan cur ;;
	--compare)
		tree old "${2:?--compare needs the old ref}"
		tree new "${3:---index}"
		scan old > "$WORK/old.json"
		scan new > "$WORK/new.json"
		if diff -u "$WORK/old.json" "$WORK/new.json"; then
			echo "route-security-inventory: identical ($(grep -c '::' "$WORK/old.json") methods)" >&2
		else
			echo "route-security-inventory: DIFFERENT" >&2
			exit 1
		fi
		;;
	*) echo "usage: $0 [--index | --ref <git-ref> | --compare <old-ref> [<new-ref>|--index]]" >&2; exit 2 ;;
esac

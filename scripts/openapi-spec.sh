#!/usr/bin/env bash
# openapi-spec.sh — generate app/openapi.json from the controllers with the pinned
# nextcloud/openapi-extractor (app/composer.lock), in a throwaway container (GitHub #65).
#
# Usage: scripts/openapi-spec.sh [--check|--write] [--index | --ref <git-ref>]
#   --check (default)  generate from the snapshot and compare with the snapshot's app/openapi.json;
#                      exit 1 when it is missing or differs (CI and pre-push)
#   --write            generate from the snapshot and write it to app/openapi.json in the working copy
#   --index (default)  snapshot = what `git commit` would record; --ref <git-ref> = that commit
#
# What the spec covers: every route in appinfo/routes.php (all controllers carry
# #[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT)]) with path, method, path/query parameters and their
# types from the method signatures, and the CSRF requirement: a route that is not
# @NoCSRFRequired gets the required header parameter `OCS-APIRequest` (the extractor's marker for
# "needs the request token"). The frontend contract test (app/tests/unit/apiContract.test.js)
# reads exactly that.
#
# What it does not cover yet: response bodies. Most controller methods have no typed
# `@return DataResponse<...>` annotation; the extractor reports each of them as an error, writes the
# spec anyway and then exits with "Encountered N errors that need to be fixed!". This script accepts
# that exit only when
#   - it is exactly that message (any other panic fails),
#   - N equals the number in scripts/openapi-error-baseline.txt (more = new undocumented code,
#     fewer = lower the baseline in the same commit: the number may only shrink),
#   - the spec was written, is valid JSON, and contains every route of appinfo/routes.php.
# Overrides: GATE_IMAGE, GATE_COMPOSER_VERSION, GATE_COMPOSER_SHA256 (as in gate-container.sh).
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
GIT_ROOT="${GATE_GIT_ROOT:-$REPO_ROOT}"

IMAGE="${GATE_IMAGE:-nextcloud:33@sha256:df735d59202b74546ca4f935ec860d8397995a6e4e353926c4876547f6c6c4a5}"
COMPOSER_VERSION="${GATE_COMPOSER_VERSION:-2.10.3}"
COMPOSER_SHA256="${GATE_COMPOSER_SHA256:-7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6}"

WORK=""
NAME=""
cleanup() {
	local rc=$? ok=1
	if [ -n "$NAME" ]; then
		if ! docker rm -f -v "$NAME" >/dev/null; then
			echo "openapi-spec: CLEANUP FAILED: could not remove container $NAME" >&2
			ok=0
		fi
	fi
	if [ -n "$WORK" ] && [ -d "$WORK" ]; then
		rm -r -- "$WORK" || { echo "openapi-spec: CLEANUP FAILED: could not remove $WORK" >&2; ok=0; }
	fi
	if [ "$ok" = 0 ] && [ "$rc" -eq 0 ]; then rc=3; fi
	exit "$rc"
}
trap cleanup EXIT

die() { echo "openapi-spec: $*" >&2; exit 2; }

action=--check src=--index ref=""
while [ $# -gt 0 ]; do
	case "$1" in
		--check|--write) action="$1" ;;
		--index) src=--index ;;
		--ref) src=--ref; ref="${2:-}"; [ -n "$ref" ] || die "--ref needs a git ref"; shift ;;
		*) die "usage: $0 [--check|--write] [--index|--ref <git-ref>]" ;;
	esac
	shift
done

WORK="$(mktemp -d "${TMPDIR:-/tmp}/learning-openapi.XXXXXX")"
mkdir -p "$WORK/repo"
case "$src" in
	--index) git -C "$GIT_ROOT" checkout-index -a --prefix="$WORK/repo/" ;;
	--ref)   git -C "$GIT_ROOT" archive "$ref" | tar -x -C "$WORK/repo" ;;
esac
[ -f "$WORK/repo/app/composer.lock" ] || die "snapshot has no app/composer.lock"
BASELINE_FILE="$WORK/repo/scripts/openapi-error-baseline.txt"
[ -f "$BASELINE_FILE" ] || die "snapshot has no scripts/openapi-error-baseline.txt"
BASELINE="$(grep -v '^#' "$BASELINE_FILE" | tr -d '[:space:]')"
[[ "$BASELINE" =~ ^[0-9]+$ ]] || die "scripts/openapi-error-baseline.txt holds no number"

NAME="learning-openapi-$$"
echo "==> container $NAME ($IMAGE)"
docker run -d --name "$NAME" --entrypoint sleep "$IMAGE" infinity >/dev/null
docker cp "$WORK/repo/app/." "$NAME:/work"
docker exec -e COMPOSER_VERSION="$COMPOSER_VERSION" -e COMPOSER_SHA256="$COMPOSER_SHA256" "$NAME" sh -euc '
	curl -fsSL -o /usr/local/bin/composer "https://getcomposer.org/download/$COMPOSER_VERSION/composer.phar"
	echo "$COMPOSER_SHA256  /usr/local/bin/composer" | sha256sum -c --quiet
	chmod +x /usr/local/bin/composer
	cd /work && COMPOSER_ALLOW_SUPERUSER=1 composer install -q --no-interaction --no-progress
	test -x vendor/bin/generate-spec || { echo "nextcloud/openapi-extractor is not installed (composer.lock)" >&2; exit 1; }
'

echo "==> generate-spec (nextcloud/openapi-extractor $(docker exec -w /work "$NAME" sh -c "COMPOSER_ALLOW_SUPERUSER=1 composer show nextcloud/openapi-extractor 2>/dev/null | sed -n 's/^versions : \* //p'"))"
rc=0
docker exec -w /work "$NAME" sh -c 'php vendor/bin/generate-spec . /tmp/openapi.json --allow-missing-docs > /tmp/openapi.log 2>&1' || rc=$?
docker cp "$NAME:/tmp/openapi.log" "$WORK/openapi.log"
sed -i 's/\x1b\[[0-9;]*m//g' "$WORK/openapi.log"

# The only accepted failure is the final error count; every other panic fails.
errors=0
if [ "$rc" -ne 0 ]; then
	fatal="$(grep -E 'Uncaught|Fatal' "$WORK/openapi.log" || true)"
	if ! grep -qE '^Fatal error: Uncaught Error: app: Encountered [0-9]+ errors that need to be fixed!$' <<<"$fatal" \
		|| [ "$(wc -l <<<"$fatal")" -ne 1 ]; then
		echo "openapi-spec: the extractor failed (exit $rc) with something other than its error count:" >&2
		grep -E 'Uncaught|Fatal|Error:' "$WORK/openapi.log" | head -20 >&2
		exit 1
	fi
	errors="$(sed -nE 's/^Fatal error: Uncaught Error: app: Encountered ([0-9]+) errors.*/\1/p' "$WORK/openapi.log")"
fi
echo "extractor errors: $errors (baseline $BASELINE)"
if [ "$errors" -gt "$BASELINE" ]; then
	echo "openapi-spec: $errors extractor errors, baseline $BASELINE — new ones (only the new ones need fixing):" >&2
	grep '^Error:' "$WORK/openapi.log" | grep -v 'Missing @return annotation' | head -30 >&2
	exit 1
elif [ "$errors" -lt "$BASELINE" ]; then
	echo "openapi-spec: $errors extractor errors, fewer than the baseline $BASELINE — set scripts/openapi-error-baseline.txt to $errors in this commit" >&2
	exit 1
fi

# Every route of appinfo/routes.php must be in the spec: a route the extractor skips (for example
# "Returns no responses") would otherwise be an unknown route to the contract test.
docker exec -w /work "$NAME" php -r '
	$spec = json_decode((string)file_get_contents("/tmp/openapi.json"), true);
	if (!is_array($spec) || !isset($spec["paths"]) || !is_array($spec["paths"])) { fwrite(STDERR, "openapi.json is not a spec\n"); exit(1); }
	$ops = [];
	foreach ($spec["paths"] as $path => $methods) { foreach ($methods as $m => $_) { $ops[strtoupper($m) . " " . $path] = true; } }
	$routes = require "appinfo/routes.php";
	$missing = [];
	$n = 0;
	foreach ($routes as $kind => $list) {
		if ($kind !== "routes") { fwrite(STDERR, "routes.php has a \"$kind\" section, not handled here\n"); exit(1); }
		foreach ($list as $r) {
			$n++;
			$url = rtrim("/index.php/apps/learning" . $r["url"], "/");
			$key = strtoupper($r["verb"] ?? "GET") . " " . $url;
			if (!isset($ops[$key])) { $missing[] = $r["name"] . " " . $key; }
		}
	}
	if ($missing !== []) { fwrite(STDERR, "routes missing from the spec:\n  " . implode("\n  ", $missing) . "\n"); exit(1); }
	echo "routes in spec: $n of $n\n";
'

docker cp "$NAME:/tmp/openapi.json" "$WORK/openapi.json"
case "$action" in
	--write)
		cp "$WORK/openapi.json" "$REPO_ROOT/app/openapi.json"
		echo "wrote app/openapi.json"
		;;
	--check)
		if [ ! -f "$WORK/repo/app/openapi.json" ]; then
			echo "openapi-spec: app/openapi.json is not in the snapshot — run scripts/openapi-spec.sh --write and commit it" >&2
			exit 1
		fi
		if ! cmp -s "$WORK/openapi.json" "$WORK/repo/app/openapi.json"; then
			echo "openapi-spec: app/openapi.json is out of date — run scripts/openapi-spec.sh --write and commit it:" >&2
			diff -u "$WORK/repo/app/openapi.json" "$WORK/openapi.json" | head -40 >&2 || true
			exit 1
		fi
		echo "app/openapi.json is up to date"
		;;
esac

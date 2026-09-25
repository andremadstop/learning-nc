#!/usr/bin/env bash
# gate-container.sh — run PHPStan and/or PHPUnit for app/ in a throwaway Docker container.
#
# Usage: scripts/gate-container.sh [phpstan|phpunit|all] [--index | --ref <git-ref>]
#        scripts/gate-container.sh --self-test
#
# Source is a clean snapshot, never the dirty working copy:
#   --index (default)  what `git commit` would record (git checkout-index)
#   --ref <git-ref>    any commit/tag/branch (git archive)
# Exit 0 only if every requested gate passed and the container was removed.
# The last output line is always
#   GATE-RESULT phpstan=<ok|fail> phpunit=<ok|fail>
# A gate that was not requested reports ok (it cannot block); any setup or cleanup
# failure reports fail for every requested gate.
#
# PHPUnit runs the versioned app/phpunit.xml with --fail-on-skipped --fail-on-incomplete:
# a skipped test is a check that never ran, so it fails the gate. Tests that need a real
# Nextcloud database live in app/tests/Integration and run via scripts/nc-integration.sh.
#
# --self-test proves failures are actually caught: a PHPStan probe with a deliberate error
# must fail and the clean snapshot must pass; a PHPUnit probe that skips, and one that calls
# exit(0) mid-run, must each fail the gate.
# Overrides (environment variables): see scripts/gate-container.env.example.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# Git operations use GATE_GIT_ROOT when set: the hooks run this script from a snapshot of the
# commit under test (so an uncommitted edit to the gate cannot vouch for the commit), and the
# snapshot itself is not the repository.
GIT_ROOT="${GATE_GIT_ROOT:-$REPO_ROOT}"

IMAGE="${GATE_IMAGE:-nextcloud:33@sha256:df735d59202b74546ca4f935ec860d8397995a6e4e353926c4876547f6c6c4a5}"
COMPOSER_VERSION="${GATE_COMPOSER_VERSION:-2.10.3}"
COMPOSER_SHA256="${GATE_COMPOSER_SHA256:-7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6}"

WORK=""
NAME=""
PS=fail   # verdicts; main/self_test set them, the EXIT trap prints them
PU=fail

cleanup() {
	local rc=$? cleanup_ok=1
	if [ -n "$NAME" ]; then
		if ! docker rm -f -v "$NAME" >/dev/null; then   # -v: the image declares VOLUMEs
			echo "gate-container: CLEANUP FAILED: could not remove container $NAME" >&2
			cleanup_ok=0
		elif docker container inspect "$NAME" >/dev/null 2>&1; then
			echo "gate-container: CLEANUP FAILED: container $NAME still exists" >&2
			cleanup_ok=0
		fi
	fi
	if [ -n "$WORK" ] && [ -d "$WORK" ]; then
		if ! rm -r -- "$WORK"; then
			echo "gate-container: CLEANUP FAILED: could not remove $WORK" >&2
			cleanup_ok=0
		fi
	fi
	if [ "$cleanup_ok" = 0 ]; then
		PS=fail; PU=fail
		[ "$rc" -ne 0 ] || rc=3
	fi
	echo "GATE-RESULT phpstan=$PS phpunit=$PU"
	exit "$rc"
}
trap cleanup EXIT

die() { echo "gate-container: $*" >&2; exit 2; }

# snapshot <--index|--ref> [ref] — writes a clean tree to $WORK/repo
snapshot() {
	WORK="$(mktemp -d "${TMPDIR:-/tmp}/learning-gate.XXXXXX")"
	mkdir -p "$WORK/repo"
	case "$1" in
		--index) git -C "$GIT_ROOT" checkout-index -a --prefix="$WORK/repo/" ;;
		--ref)   git -C "$GIT_ROOT" archive "$2" | tar -x -C "$WORK/repo" ;;
		*)       die "unknown source mode: $1" ;;
	esac
	[ -f "$WORK/repo/app/composer.json" ] || die "snapshot has no app/composer.json"
	[ -f "$WORK/repo/app/composer.lock" ] || die "snapshot has no app/composer.lock (gates must be reproducible)"
	[ -f "$WORK/repo/app/phpunit.xml" ] || die "snapshot has no app/phpunit.xml"
}

# start_container <purpose> — container with app at /work, vendor installed
start_container() {
	NAME="learning-gate-$1-$$"
	echo "==> container $NAME ($IMAGE)"
	docker run -d --name "$NAME" --entrypoint sleep "$IMAGE" infinity >/dev/null
	docker cp "$WORK/repo/app/." "$NAME:/work"
	docker cp "$WORK/repo/scripts/verify-credential.py" "$NAME:/tmp/verify-credential.py"
	docker exec -e COMPOSER_VERSION="$COMPOSER_VERSION" -e COMPOSER_SHA256="$COMPOSER_SHA256" "$NAME" sh -euc '
		ln -sf /usr/src/nextcloud/3rdparty /var/www/html/3rdparty
		export DEBIAN_FRONTEND=noninteractive
		apt-get update -qq >/dev/null
		apt-get install -y -qq python3-cryptography >/dev/null
		curl -fsSL -o /usr/local/bin/composer "https://getcomposer.org/download/$COMPOSER_VERSION/composer.phar"
		echo "$COMPOSER_SHA256  /usr/local/bin/composer" | sha256sum -c --quiet
		chmod +x /usr/local/bin/composer
		cd /work && COMPOSER_ALLOW_SUPERUSER=1 composer install -q --no-interaction --no-progress
	'
}

run_phpstan() {
	echo "==> PHPStan"
	docker exec -w /work "$NAME" php -d memory_limit=2G vendor/bin/phpstan analyse --no-progress
}

# run_phpunit [test-file] — the gate's PHPUnit invocation (optionally limited to one file).
# Exit 0 is not enough: code that calls exit(0) ends PHPUnit early with a clean exit code. The run
# counts only if PHPUnit also wrote its JUnit report at the end, with >0 tests and no error,
# failure or skip.
run_phpunit() {
	echo "==> PHPUnit (app/phpunit.xml)"
	docker exec "$NAME" rm -f /tmp/gate-junit.xml
	docker exec -w /work -e VERIFY_SCRIPT=/tmp/verify-credential.py "$NAME" \
		php vendor/bin/phpunit -c phpunit.xml --log-junit /tmp/gate-junit.xml \
		--fail-on-skipped --fail-on-incomplete --display-skipped --display-incomplete "$@" || return
	docker exec "$NAME" php -r '$f=$argv[1]; if(!is_file($f)){fwrite(STDERR,"no JUnit report: PHPUnit did not finish\n");exit(1);} $x=@simplexml_load_string((string)file_get_contents($f)); $s=$x?$x->testsuite:null; if(!$s){fwrite(STDERR,"JUnit report unreadable\n");exit(1);} $t=(int)$s["tests"];$e=(int)$s["errors"];$fl=(int)$s["failures"];$sk=(int)$s["skipped"]; echo "junit tests=$t errors=$e failures=$fl skipped=$sk\n"; exit($t>0&&$e===0&&$fl===0&&$sk===0?0:1);' /tmp/gate-junit.xml
}

self_test() {
	snapshot --index
	start_container selftest
	local out rc

	docker exec "$NAME" sh -c 'printf "<?php\nreturn \$undefined;\n" > /work/lib/__gate_probe.php'
	echo "==> self-test 1/4: PHPStan probe with deliberate error must fail"
	rc=0; out="$(run_phpstan 2>&1)" || rc=$?
	echo "$out" | tail -n 15
	if [ "$rc" -eq 0 ] || ! grep -q "__gate_probe.php" <<<"$out"; then
		echo "SELF-TEST FAIL: deliberate PHPStan error was not reported" >&2
		exit 1
	fi
	echo "SELF-TEST phpstan probe: fail (expected)"
	docker exec "$NAME" rm -f /work/lib/__gate_probe.php

	echo "==> self-test 2/4: clean snapshot must pass PHPStan"
	if ! run_phpstan; then
		echo "SELF-TEST FAIL: clean snapshot does not pass PHPStan" >&2
		exit 1
	fi
	echo "SELF-TEST clean: phpstan ok (expected)"
	PS=ok

	docker exec "$NAME" sh -c 'cat > /work/tests/Unit/GateSkipProbeTest.php <<"EOF"
<?php
use PHPUnit\Framework\TestCase;
final class GateSkipProbeTest extends TestCase {
	public function testSkips(): void { $this->markTestSkipped("gate skip probe"); }
}
EOF'
	echo "==> self-test 3/4: PHPUnit probe that only skips must fail the gate"
	rc=0; out="$(run_phpunit tests/Unit/GateSkipProbeTest.php 2>&1)" || rc=$?
	echo "$out" | tail -n 12
	if [ "$rc" -eq 0 ] || ! grep -q "gate skip probe" <<<"$out"; then
		echo "SELF-TEST FAIL: a skipped test did not fail the PHPUnit gate" >&2
		exit 1
	fi
	echo "SELF-TEST phpunit skip probe: fail (expected, exit $rc)"
	docker exec "$NAME" rm -f /work/tests/Unit/GateSkipProbeTest.php

	docker exec "$NAME" sh -c 'cat > /work/tests/Unit/GateExitProbeTest.php <<"EOF"
<?php
use PHPUnit\Framework\TestCase;
final class GateExitProbeTest extends TestCase {
	public function testExits(): void { exit(0); }
}
EOF'
	echo "==> self-test 4/4: a test that calls exit(0) must fail the gate (PHPUnit exits 0 early)"
	rc=0; out="$(run_phpunit tests/Unit/GateExitProbeTest.php 2>&1)" || rc=$?
	echo "$out" | tail -n 5
	if [ "$rc" -eq 0 ] || ! grep -qE "no JUnit report|JUnit report unreadable" <<<"$out"; then
		echo "SELF-TEST FAIL: an early exit(0) passed the PHPUnit gate" >&2
		exit 1
	fi
	echo "SELF-TEST phpunit exit probe: fail (expected, exit $rc)"
	PU=ok
	echo "SELF-TEST PASS"
}

main() {
	if [ "${1:-}" = "--self-test" ]; then self_test; return; fi

	local mode="${1:-all}" src="${2:---index}" ref=""
	case "$mode" in phpstan|phpunit|all) ;; *) die "usage: $0 [phpstan|phpunit|all] [--index|--ref <git-ref>] | --self-test" ;; esac
	case "$src" in
		--index) ;;
		--ref) ref="${3:-}"; [ -n "$ref" ] || die "--ref needs a git ref" ;;
		*) die "unknown source: $src" ;;
	esac

	[ "$mode" != phpunit ] || PS=ok   # not requested -> cannot block
	[ "$mode" != phpstan ] || PU=ok

	snapshot "$src" "$ref"
	start_container "$mode"

	local ok=1
	if [ "$mode" != phpunit ]; then if run_phpstan; then PS=ok; else PS=fail; ok=0; fi; fi
	if [ "$mode" != phpstan ]; then if run_phpunit; then PU=ok; else PU=fail; ok=0; fi; fi
	[ "$ok" = 1 ]
}

main "$@"

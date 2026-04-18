#!/bin/bash
# Test harness for scripts/check-forbidden-names.sh
#
# Verifies:
#   - Script exists + is executable
#   - Clean repo: exit 0
#   - Plant in scope (app/src/): exit 1 for each forbidden name
#   - "cosmology"/"cosmological" in scope: exit 0 (whitelist)
#   - "Cosmos" standalone in scope: exit 1
#   - LEGAL-EXCEPTION marker: hits on same line are ignored → exit 0
#   - .planning/ is NOT scanned: plant there must not cause failure
#   - Runtime < 2 seconds on current repo
#
# Usage: bash scripts/check-forbidden-names.test.sh

set -u

SCRIPT="scripts/check-forbidden-names.sh"
ROOT_DIR="$(git rev-parse --show-toplevel)"
cd "$ROOT_DIR" || exit 99

PASS=0
FAIL=0

assert() {
    local name="$1"
    local expected="$2"
    local actual="$3"
    if [ "$expected" = "$actual" ]; then
        echo "  PASS: $name (exit=$actual)"
        PASS=$((PASS + 1))
    else
        echo "  FAIL: $name (expected=$expected actual=$actual)"
        FAIL=$((FAIL + 1))
    fi
}

cleanup() {
    rm -f app/src/_forbidden_test.tmp.md \
          app/src/_cosmology_test.tmp.md \
          app/src/_cosmos_show_test.tmp.md \
          app/src/_exception_test.tmp.md \
          .planning/_planning_scope_test.tmp.md
}
trap cleanup EXIT

echo "=== check-forbidden-names.sh test harness ==="

# 1. Script exists + executable
echo "Test 1: script exists + executable"
if [ -x "$SCRIPT" ]; then
    echo "  PASS: $SCRIPT is executable"
    PASS=$((PASS + 1))
else
    echo "  FAIL: $SCRIPT missing or not +x"
    FAIL=$((FAIL + 1))
    echo "=== SUMMARY: $PASS passed, $FAIL failed ==="
    exit 1
fi

# 2. Clean repo: exit 0
echo "Test 2: clean repo returns exit 0"
cleanup
bash "$SCRIPT" >/dev/null 2>&1
assert "clean repo" 0 $?

# 3. Plant Einstein in scope: exit 1
echo "Test 3: Einstein in app/src/ triggers fail"
echo "Einstein was a theoretical physicist" > app/src/_forbidden_test.tmp.md
bash "$SCRIPT" >/dev/null 2>&1
assert "Einstein plant" 1 $?
rm -f app/src/_forbidden_test.tmp.md

# 4. Plant Hawking
echo "Test 4: Hawking in app/src/ triggers fail"
echo "Stephen Hawking wrote A Brief History of Time" > app/src/_forbidden_test.tmp.md
bash "$SCRIPT" >/dev/null 2>&1
assert "Hawking plant" 1 $?
rm -f app/src/_forbidden_test.tmp.md

# 5. Plant Tyson
echo "Test 5: Tyson in app/src/ triggers fail"
echo "Tyson hosted a podcast" > app/src/_forbidden_test.tmp.md
bash "$SCRIPT" >/dev/null 2>&1
assert "Tyson plant" 1 $?
rm -f app/src/_forbidden_test.tmp.md

# 6. Plant "Neil deGrasse"
echo "Test 6: 'Neil deGrasse' in app/src/ triggers fail"
echo "Neil deGrasse popularised cosmology" > app/src/_forbidden_test.tmp.md
bash "$SCRIPT" >/dev/null 2>&1
assert "Neil deGrasse plant" 1 $?
rm -f app/src/_forbidden_test.tmp.md

# 7. Plant StarTalk
echo "Test 7: StarTalk in app/src/ triggers fail"
echo "StarTalk is a radio show" > app/src/_forbidden_test.tmp.md
bash "$SCRIPT" >/dev/null 2>&1
assert "StarTalk plant" 1 $?
rm -f app/src/_forbidden_test.tmp.md

# 8. Plant "Cosmos" as show-title: exit 1
echo "Test 8: 'Cosmos' as standalone token triggers fail"
echo "Watch Cosmos on Netflix" > app/src/_cosmos_show_test.tmp.md
bash "$SCRIPT" >/dev/null 2>&1
assert "Cosmos show-title plant" 1 $?
rm -f app/src/_cosmos_show_test.tmp.md

# 9. Plant "cosmology" / "cosmological": exit 0 (whitelist)
echo "Test 9: 'cosmology' in scope is allowed"
echo "Cosmology studies the universe. Cosmological constants matter." > app/src/_cosmology_test.tmp.md
bash "$SCRIPT" >/dev/null 2>&1
assert "cosmology whitelist" 0 $?
rm -f app/src/_cosmology_test.tmp.md

# 10. LEGAL-EXCEPTION marker on same line: line ignored → exit 0
echo "Test 10: LEGAL-EXCEPTION marker bypass"
echo "Hawking is a trademark issue // LEGAL-EXCEPTION: trademark discussion" > app/src/_exception_test.tmp.md
bash "$SCRIPT" >/dev/null 2>&1
assert "LEGAL-EXCEPTION bypass" 0 $?
rm -f app/src/_exception_test.tmp.md

# 11. .planning/ NOT scanned: plant there → exit 0
echo "Test 11: .planning/ is out of scope"
mkdir -p .planning
echo "Einstein Hawking Tyson StarTalk Cosmos" > .planning/_planning_scope_test.tmp.md
bash "$SCRIPT" >/dev/null 2>&1
assert ".planning/ scope exclusion" 0 $?
rm -f .planning/_planning_scope_test.tmp.md

# 12. Runtime < 2 seconds on current repo
echo "Test 12: runtime budget < 2s"
START=$(date +%s%N)
bash "$SCRIPT" >/dev/null 2>&1
END=$(date +%s%N)
ELAPSED_MS=$(( (END - START) / 1000000 ))
if [ "$ELAPSED_MS" -lt 2000 ]; then
    echo "  PASS: runtime ${ELAPSED_MS}ms (< 2000ms)"
    PASS=$((PASS + 1))
else
    echo "  FAIL: runtime ${ELAPSED_MS}ms (>= 2000ms)"
    FAIL=$((FAIL + 1))
fi

# 13. Fail output format: path:line:content
echo "Test 13: fail output contains path:line:content"
echo "Einstein fail-format-test" > app/src/_forbidden_test.tmp.md
OUTPUT=$(bash "$SCRIPT" 2>&1 || true)
if echo "$OUTPUT" | grep -qE "app/src/_forbidden_test\.tmp\.md:[0-9]+:"; then
    echo "  PASS: fail output matches path:line:content"
    PASS=$((PASS + 1))
else
    echo "  FAIL: fail output format unexpected"
    echo "  --- output ---"
    echo "$OUTPUT" | head -10
    echo "  --- end ---"
    FAIL=$((FAIL + 1))
fi
rm -f app/src/_forbidden_test.tmp.md

echo ""
echo "=== SUMMARY: $PASS passed, $FAIL failed ==="
[ "$FAIL" -eq 0 ]

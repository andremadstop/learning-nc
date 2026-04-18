#!/bin/bash
# scripts/check-forbidden-names.sh
#
# Gate #6 — Forbidden Names CI Check (LEGAL-02, Phase 149).
#
# Blocks production-scope references to real-person names that carry
# trademark or right-of-publicity risk for Learning-NC v4.4.0+.
#
# Scope (strict): app/src/, app/l10n/, CHANGELOG.md, appinfo/info.xml
# Scope explicitly EXCLUDES .planning/ — LEGAL.md contains the
# trademark analysis itself and would otherwise self-fail.
#
# Escape hatch: any line containing the literal marker LEGAL-EXCEPTION
# is filtered out before matching. Use sparingly, only for legal-review
# documents that MUST reference the names.
#
# Forbidden (case-insensitive, word-boundary):
#   Einstein | Hawking | Tyson | Neil deGrasse | StarTalk
#
# Context-sensitive:
#   Cosmos  — blocked as standalone token (show-title).
#             Allowed when part of cosmology/cosmological (scientific term).
#
# Exit codes: 0 = clean, 1 = forbidden hit found.
# Runtime budget: < 2 seconds on the full repo.

set -u

ROOT_DIR="$(git rev-parse --show-toplevel 2>/dev/null)"
if [ -z "$ROOT_DIR" ]; then
    echo "check-forbidden-names: not inside a git repo" >&2
    exit 2
fi

FORBIDDEN='Einstein|Hawking|Tyson|Neil deGrasse|StarTalk'

SCOPE_DIRS=()
[ -d "$ROOT_DIR/app/src" ]  && SCOPE_DIRS+=("$ROOT_DIR/app/src/")
[ -d "$ROOT_DIR/app/l10n" ] && SCOPE_DIRS+=("$ROOT_DIR/app/l10n/")

SCOPE_FILES=()
[ -f "$ROOT_DIR/CHANGELOG.md" ]       && SCOPE_FILES+=("$ROOT_DIR/CHANGELOG.md")
[ -f "$ROOT_DIR/appinfo/info.xml" ]   && SCOPE_FILES+=("$ROOT_DIR/appinfo/info.xml")

if [ ${#SCOPE_DIRS[@]} -eq 0 ] && [ ${#SCOPE_FILES[@]} -eq 0 ]; then
    # Nothing in scope — treat as clean.
    exit 0
fi

# Pass 1: hard-forbidden names (Einstein, Hawking, Tyson, Neil deGrasse, StarTalk).
HITS=$(grep -rniE "\b(${FORBIDDEN})\b" \
    --include="*.vue" --include="*.js" --include="*.ts" \
    --include="*.json" --include="*.xml" --include="*.md" \
    "${SCOPE_DIRS[@]}" "${SCOPE_FILES[@]}" 2>/dev/null \
    | grep -v "LEGAL-EXCEPTION" \
    || true)

# Pass 2: Cosmos standalone, minus cosmology whitelist.
COSMOS_HITS=$(grep -rniE '\bCosmos\b' \
    --include="*.vue" --include="*.js" --include="*.ts" \
    --include="*.json" --include="*.xml" --include="*.md" \
    "${SCOPE_DIRS[@]}" "${SCOPE_FILES[@]}" 2>/dev/null \
    | grep -v "LEGAL-EXCEPTION" \
    | grep -v -iE "cosmology|cosmological|cosmolog" \
    || true)

if [ -n "$HITS" ] || [ -n "$COSMOS_HITS" ]; then
    echo "FAIL — Forbidden name(s) found:"
    [ -n "$HITS" ]        && echo "$HITS"
    [ -n "$COSMOS_HITS" ] && echo "$COSMOS_HITS"
    exit 1
fi

exit 0

#!/bin/bash
# scripts/check-i18n-parity.sh
# Asserts all 5 supported language JSONs have the same key-set as de.json.
set -eo pipefail
ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
L10N="$ROOT_DIR/app/l10n"
CANONICAL="de"
LANGS=("en" "fr" "ru" "ar")

DE_KEYS=$(python3 -c "import json; print('\n'.join(sorted(json.load(open('$L10N/$CANONICAL.json'))['translations'].keys())))")

FAIL=0
for L in "${LANGS[@]}"; do
  L_KEYS=$(python3 -c "import json; print('\n'.join(sorted(json.load(open('$L10N/$L.json'))['translations'].keys())))")
  MISSING=$(comm -23 <(echo "$DE_KEYS") <(echo "$L_KEYS"))
  EXTRA=$(comm -13 <(echo "$DE_KEYS") <(echo "$L_KEYS"))
  if [ -n "$MISSING" ] || [ -n "$EXTRA" ]; then
    echo "FAIL: $L.json has parity drift vs $CANONICAL.json"
    [ -n "$MISSING" ] && echo "  Missing keys ($(echo "$MISSING" | wc -l)):" && echo "$MISSING" | head -5 | sed 's/^/    /'
    [ -n "$EXTRA" ] && echo "  Extra keys ($(echo "$EXTRA" | wc -l)):" && echo "$EXTRA" | head -5 | sed 's/^/    /'
    FAIL=1
  fi
done

if [ "$FAIL" -eq 1 ]; then
  echo ""
  echo "Fix: add missing keys (or remove extras) so all 5 langs share the same key-set."
  exit 1
fi
echo "i18n key-parity OK across DE/EN/FR/RU/AR ($(echo "$DE_KEYS" | wc -l | tr -d ' ') keys each)"

# Gate 2: .js<->.json value-sync.
# Key-parity above only compares .json key-SETS. The frontend reads l10n/<lang>.js,
# so a value edited in .json but not regenerated into .js (issues #18 -> #21) slips
# through. This asserts every .js equals regen(.json).
echo ""
python3 "$ROOT_DIR/scripts/l10n_js_sync.py" --check

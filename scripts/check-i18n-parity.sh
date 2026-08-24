#!/bin/bash
# scripts/check-i18n-parity.sh
# Asserts all 6 supported language JSONs have the same key-set as de.json.
#
# 164-07: comparison moved from line-based `comm` into python set arithmetic —
# a translation KEY containing an embedded newline (the VirtuProf intro) broke
# comm's sorted-line model, and locale collation made the sort order
# non-deterministic. Sets are newline- and locale-safe.
set -eo pipefail
ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
L10N="$ROOT_DIR/app/l10n"

python3 - "$L10N" <<'PYEOF'
import json, sys

l10n = sys.argv[1]
canonical = 'de'
langs = ['en', 'fr', 'ru', 'ar', 'uk']

de_keys = set(json.load(open(f'{l10n}/{canonical}.json', encoding='utf-8'))['translations'])

fail = False
for lang in langs:
    keys = set(json.load(open(f'{l10n}/{lang}.json', encoding='utf-8'))['translations'])
    missing = sorted(de_keys - keys)
    extra = sorted(keys - de_keys)
    if missing or extra:
        fail = True
        print(f'FAIL: {lang}.json has parity drift vs {canonical}.json')
        if missing:
            print(f'  Missing keys ({len(missing)}):')
            for k in missing[:5]:
                print(f'    {k!r}')
        if extra:
            print(f'  Extra keys ({len(extra)}):')
            for k in extra[:5]:
                print(f'    {k!r}')

if fail:
    print('')
    print('Fix: add missing keys (or remove extras) so all 6 langs share the same key-set.')
    sys.exit(1)

print(f'i18n key-parity OK across DE/EN/FR/RU/AR/UK ({len(de_keys)} keys each)')
PYEOF

# Gate 2: .js<->.json value-sync.
# Key-parity above only compares .json key-SETS. The frontend reads l10n/<lang>.js,
# so a value edited in .json but not regenerated into .js (issues #18 -> #21) slips
# through. This asserts every .js equals regen(.json).
echo ""
python3 "$ROOT_DIR/scripts/l10n_js_sync.py" --check

# Gate 3: placeholder + script integrity.
# A translation that drops {n}/%s renders broken at runtime while passing both
# gates above. Machine-assisted translation makes that the likeliest defect.
echo ""
python3 "$ROOT_DIR/scripts/check-i18n-placeholders.py"

# Gate 4: source coverage.
# Gates 1-3 compare the language files against EACH OTHER, so a string missing from
# ALL of them passes every one of them and then renders in German everywhere. This
# compares the SOURCE against the catalogue. Verified: removing a key from all six
# files leaves gates 1-3 green and turns this one red.
echo ""
python3 "$ROOT_DIR/scripts/check-i18n-coverage.py"

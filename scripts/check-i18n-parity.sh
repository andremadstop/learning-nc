#!/bin/bash
# scripts/check-i18n-parity.sh
# Asserts all 7 supported language JSONs have the same key-set as de.json.
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
langs = ['en', 'fr', 'ru', 'ar', 'uk', 'zh_CN']

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
    print('Fix: add missing keys (or remove extras) so all 7 langs share the same key-set.')
    sys.exit(1)

print(f'i18n key-parity OK across DE/EN/FR/RU/AR/UK/ZH_CN ({len(de_keys)} keys each)')
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

# Gate 5: the VirtuProf runtime catalogue.
# VirtuProf does not translate through t('learning', …) — it resolves with
# translateVirtuProf(lang, key) against its own bundled dictionary, so gate 4 (which
# scans for t() call sites) is blind to every string the assistant renders. Codeberg #6:
# 161 of 343 reachable keys were absent from that catalogue and fell back to the raw
# German source inside a Ukrainian UI, with gates 1-4 green throughout.
# Verified: dropping a VirtuProf key from all six l10n/*.json leaves gates 1-4 green and
# turns this one red.
echo ""
node --no-warnings "$ROOT_DIR/scripts/gen-virtuprof-strings.mjs" --check

# Gate 6: the AI consent text (app/data/ai-consent.json).
# The consent dialog renders its own six-language template, not t('learning', …), so
# gates 1-5 never read it. A locale that loses a paragraph or the {provider} placeholder
# would show the user a consent text that does not name the recipient.
# Verified: dropping {provider} from one uk paragraph, or a key from ar, turns this red
# while gates 1-5 stay green.
echo ""
python3 "$ROOT_DIR/scripts/check-consent-locales.py"

# Gate 7: English left in a translation column.
# Gates 1-3 compare the files against each other and accept Latin letters in every
# column, so a RU/AR/FR value that is just the English text passes all of them
# ('Frage löschen' -> 'Delete question' in the Russian delete dialog, 5.13.0 review).
# Ratchet: the keys untranslated when the gate came in sit in
# scripts/i18n-untranslated-baseline.json and may only shrink.
# Verified: setting one RU value that is not in the baseline to its English text
# turns this red while gates 1-6 stay green.
echo ""
python3 "$ROOT_DIR/scripts/check-i18n-untranslated.py"

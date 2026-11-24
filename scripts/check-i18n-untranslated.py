#!/usr/bin/env python3
"""Guard: a translation that is just the English text again fails, unless it is known.

Gates 1-3 compare the language files against each other and gate 4 compares the
source against the catalogue. None of them notices a Russian, Arabic or French
value that is simply the English one: the key exists, the placeholders match, and
Latin letters are allowed in every column. That is how 'Frage löschen' reached a
Russian delete dialog as 'Delete question' with every gate green (5.13.0 review).

This gate is a ratchet:
  - ALLOWED_VALUES lists values that are the same in every language on purpose
    (product names, abbreviations, protocol names) and French cognates.
  - scripts/i18n-untranslated-baseline.json lists, per language, the keys that
    were already untranslated when the gate was introduced. They are debt, not
    approval: translate them and remove them from the baseline (--prune does the
    removal for every key that is no longer identical).
  - Any other value identical to the English one fails.
  - A baseline key that is translated by now also fails, so the baseline can only
    shrink. Run with --prune to drop those entries.

Usage:
    python3 scripts/check-i18n-untranslated.py           # exit 1 on any finding
    python3 scripts/check-i18n-untranslated.py --prune   # drop translated baseline keys
"""
import json
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
L10N = os.path.join(ROOT, "app", "l10n")
BASELINE = os.path.join(ROOT, "scripts", "i18n-untranslated-baseline.json")
LANGS = ["fr", "ru", "ar", "uk", "zh_CN"]

# Values that are correctly the same as the English text. Per language: '*' applies
# to all of them. Keep this to real names and cognates, never to sentences.
ALLOWED_VALUES = {
    "*": {
        "Leitner", "Logo", "Terminal", "Smart Queue", "VirtuProf", "XP", "PBQ", "MCQ",
        "CSV", "JSON", "PDF", "PNG", "URL", "DNS", "VLAN", "OSPF", "RAG", "LLM", "API",
        "YouTube", "Vimeo", "Nextcloud", "Talk", "OK", "ID",
    },
    "fr": {"Signature", "Source", "Minutes", "Document", "%n question", "%n questions"},
}

# A value without three letters in a row (numbers, symbols, "%s") needs no translation.
WORDY = re.compile(r"[^\W\d_]{3,}")


def load(lang):
    with open(os.path.join(L10N, f"{lang}.json"), encoding="utf-8") as fh:
        return json.load(fh)["translations"]


def identical_keys(en, translations, lang):
    allowed = ALLOWED_VALUES["*"] | ALLOWED_VALUES.get(lang, set())
    out = set()
    for key, value in translations.items():
        if value != en.get(key):
            continue
        # A plural value (n()) is a list of forms: allowed when every form is.
        values = value if isinstance(value, list) else [value]
        if all(v in allowed or not WORDY.search(v) for v in values):
            continue
        out.add(key)
    return out


def main(argv):
    prune = "--prune" in argv
    en = load("en")
    with open(BASELINE, encoding="utf-8") as fh:
        baseline = {lang: set(keys) for lang, keys in json.load(fh).items()}

    failures = 0
    pruned = {}
    for lang in LANGS:
        current = identical_keys(en, load(lang), lang)
        known = baseline.get(lang, set())
        new = sorted(current - known)
        stale = sorted(known - current)
        for key in new:
            failures += 1
            print(f"FAIL {lang}: value is the English text, not a translation")
            print(f"  key   {key!r}")
            print(f"  value {en[key]!r}")
        if stale and not prune:
            failures += 1
            print(f"FAIL {lang}: {len(stale)} baseline key(s) are translated now; run with --prune")
            for key in stale[:5]:
                print(f"  {key!r}")
        pruned[lang] = sorted(known & current)

    if prune:
        with open(BASELINE, "w", encoding="utf-8") as fh:
            json.dump(pruned, fh, ensure_ascii=False, indent=1, sort_keys=True)
            fh.write("\n")
        print("baseline pruned: " + ", ".join(f"{l} {len(pruned[l])}" for l in LANGS))
        return 1 if any(identical_keys(en, load(l), l) - baseline.get(l, set()) for l in LANGS) else 0

    if failures:
        print(f"\ni18n untranslated check: {failures} failure(s)")
        return 1

    debt = ", ".join(f"{l.upper()} {len(baseline.get(l, ()))}" for l in LANGS)
    print(f"i18n untranslated check OK: no new English values (known debt: {debt})")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))

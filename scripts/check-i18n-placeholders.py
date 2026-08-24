#!/usr/bin/env python3
"""Guard: every translated value must carry the same placeholders as its key.

Key-parity (check-i18n-parity.sh) compares key SETS and .js<->.json values, but a
translation that silently drops `{n}`, `%s` or `%1$s` passes both and then breaks
at runtime — the string renders with a literal gap or the substitution throws.
Machine-assisted translation makes that the single most likely defect class, so
it gets its own gate.

The reference is the GERMAN value, not the key: most keys are their own source
string, but a few are symbolic (`recert_reminder_subject`), where the key carries
no placeholders at all and only the value does. Comparing against de.json handles
both shapes.

Also checks that a value written in the wrong script (e.g. Cyrillic text pasted
into the Arabic column) is caught — a copy/paste class of error that no
placeholder or key check can see.

Usage:
    python3 scripts/check-i18n-placeholders.py     # exit 1 on any finding
"""
import json
import os
import re
import sys
import unicodedata

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
L10N = os.path.join(ROOT, "app", "l10n")
LANGS = ["de", "en", "fr", "ru", "ar", "uk"]

# {name} / %s / %d / %n / %1$s
PLACEHOLDER = re.compile(r"\{[a-zA-Z_]\w*\}|%[nsd]|%\d+\$s")

# Script a language's prose is expected to be written in.
EXPECTED_SCRIPT = {
    "de": "LATIN", "en": "LATIN", "fr": "LATIN",
    "ru": "CYRILLIC", "ar": "ARABIC", "uk": "CYRILLIC",
}
FOREIGN = {"CYRILLIC", "ARABIC"}


def scripts_in(text):
    found = set()
    for ch in text:
        if not ch.isalpha():
            continue
        try:
            name = unicodedata.name(ch)
        except ValueError:
            continue
        for script in ("CYRILLIC", "ARABIC", "LATIN"):
            if script in name:
                found.add(script)
                break
    return found


def main():
    failures = 0
    de_translations = json.load(open(f"{L10N}/de.json", encoding="utf-8"))["translations"]
    de_keys = list(de_translations)

    # Source-of-truth placeholder set per key, taken from the German value.
    reference = {k: sorted(PLACEHOLDER.findall(v)) for k, v in de_translations.items()}

    # A key that IS a source string must agree with its own German value.
    for key, want in reference.items():
        in_key = sorted(PLACEHOLDER.findall(key))
        if in_key and in_key != want:
            failures += 1
            print("FAIL de: source string and its translation disagree")
            print(f"  key   {key!r} -> {in_key}")
            print(f"  value {de_translations[key]!r} -> {want}")

    for lang in LANGS:
        with open(f"{L10N}/{lang}.json", encoding="utf-8") as fh:
            translations = json.load(fh)["translations"]
        expected_script = EXPECTED_SCRIPT[lang]

        for key in de_keys:
            value = translations.get(key)
            if value is None:
                continue  # key-parity script owns that failure mode

            want = reference[key]
            got = sorted(PLACEHOLDER.findall(value))
            if want != got:
                failures += 1
                print(f"FAIL {lang}: placeholder mismatch")
                print(f"  key   {key!r}")
                print(f"  value {value!r}")
                print(f"  expected {want} but found {got}")

            # Wrong-script detection: value carries a foreign script but not the
            # one this language is written in.
            present = scripts_in(value)
            if present and expected_script not in present and (present & FOREIGN):
                failures += 1
                print(f"FAIL {lang}: value is not written in {expected_script}")
                print(f"  key   {key!r}")
                print(f"  value {value!r}")

    if failures:
        print(f"\ni18n placeholder/script check: {failures} failure(s)")
        return 1

    print(f"i18n placeholder + script check OK across {'/'.join(l.upper() for l in LANGS)} "
          f"({len(de_keys)} keys each)")
    return 0


if __name__ == "__main__":
    sys.exit(main())

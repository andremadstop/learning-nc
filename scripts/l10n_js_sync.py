#!/usr/bin/env python3
"""Canonical generator + guard for l10n/<lang>.js from l10n/<lang>.json.

The frontend (@nextcloud/l10n) reads l10n/<lang>.js, while the backend/App Store
reads l10n/<lang>.json. The .json is the source of truth; the .js is a
deterministic transform of it (NC's OC.L10N.register format).

History: issues #18 -> #21 were caused by editing only .json and leaving .js
stale, so EN course-detail tabs/FAQ/arena stayed German for end users. The
key-set parity check (check-i18n-parity.sh) could not catch a value drift
between .js and .json. This module is both the regenerator and the guard.

Usage:
    python3 scripts/l10n_js_sync.py            # regenerate all <lang>.js from <lang>.json
    python3 scripts/l10n_js_sync.py --check    # verify .js == regen(.json); exit 1 on drift
"""
import json
import os
import sys
import difflib

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
L10N = os.path.join(ROOT, "app", "l10n")
LANGS = ["de", "en", "fr", "ru", "ar"]
DEFAULT_PLURAL = "nplurals=2; plural=(n != 1);"


def generate(lang: str) -> str:
    """Render l10n/<lang>.js content from l10n/<lang>.json (deterministic)."""
    with open(os.path.join(L10N, f"{lang}.json"), encoding="utf-8") as f:
        data = json.load(f)
    tr = data["translations"]
    plural = data.get("pluralForm", DEFAULT_PLURAL)
    lines = ["OC.L10N.register(", '    "learning",', "    {"]
    for k, v in tr.items():
        lines.append(
            "    " + json.dumps(k, ensure_ascii=False)
            + " : " + json.dumps(v, ensure_ascii=False) + ","
        )
    lines.append("},")
    lines.append(json.dumps(plural, ensure_ascii=False) + ");")
    return "\n".join(lines) + "\n"


def check() -> int:
    """Return 0 if every .js matches regen(.json), else 1 (and report drift)."""
    failed = []
    for lang in LANGS:
        js_path = os.path.join(L10N, f"{lang}.js")
        expected = generate(lang)
        actual = open(js_path, encoding="utf-8").read() if os.path.exists(js_path) else ""
        if actual == expected:
            continue
        failed.append(lang)
        print(f"FAIL: l10n/{lang}.js is out of sync with l10n/{lang}.json")
        diff = list(difflib.unified_diff(
            actual.splitlines(), expected.splitlines(),
            fromfile=f"{lang}.js (actual)", tofile=f"regen({lang}.json) (expected)",
            lineterm="", n=0,
        ))
        # Show a bounded sample of the drifted translation lines.
        for line in diff[:24]:
            print("  " + line)
        if len(diff) > 24:
            print(f"  ... ({len(diff) - 24} more diff lines)")
    if failed:
        print("")
        print(f"i18n .js<->.json value drift in: {', '.join(failed)}")
        print("Fix: python3 scripts/l10n_js_sync.py   (regenerates .js from .json)")
        return 1
    print(f"i18n .js<->.json value-sync OK across {'/'.join(L.upper() for L in LANGS)}")
    return 0


def regenerate() -> int:
    for lang in LANGS:
        content = generate(lang)
        with open(os.path.join(L10N, f"{lang}.js"), "w", encoding="utf-8") as f:
            f.write(content)
        n = content.count(" : ")
        print(f"{lang}.js: {n} keys written")
    return 0


if __name__ == "__main__":
    sys.exit(check() if "--check" in sys.argv else regenerate())

#!/usr/bin/env python3
"""Guard: every translatable literal in the source must exist in de.json.

The parity and placeholder gates compare the language files against EACH OTHER.
They cannot see a string that is missing from all of them at once — and that is
exactly what happened: 618 strings were passed to t() but never registered in the
catalogue, so Nextcloud's l10n fell back to the German source and rendered them
in German in every language, English included. All six files agreed, so parity
was green the whole time.

This gate compares the SOURCE against the catalogue instead.

Covered call shapes:
  - JS/Vue   t('learning', 'literal')  /  n('learning', 'sing', 'plur', n)
  - PHP      $l->t('literal')  /  $this->l10n->t('literal')
  - indirect src/utils/toolCatalog.js labelKey / shortLabelKey, which reach
    t() as t('learning', tool.labelKey) and are invisible to a literal scan

KNOWN LIMIT — not every indirect key is resolvable. Calls of the form
t('learning', <variable>) also read from onboarding slide definitions, dashboard
tiles and a few config objects. Those are not enumerated here; when adding a new
catalogue of that shape, add it below or its strings will go untranslated
silently. Run with --list-indirect to see the call sites that remain unresolved.
"""
import json
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
APP = os.path.join(ROOT, "app")
L10N = os.path.join(APP, "l10n")

JS_T = re.compile(r"""\bt\(\s*['"]learning['"]\s*,\s*(['"])((?:\\.|(?!\1).)*)\1""", re.S)
JS_N = re.compile(
    r"""\bn\(\s*['"]learning['"]\s*,\s*(['"])((?:\\.|(?!\1).)*)\1\s*,\s*(['"])((?:\\.|(?!\3).)*)\3""",
    re.S,
)
PHP_T = re.compile(r"""->t\(\s*'((?:[^'\\]|\\.)*)'""")
TOOL_KEY = re.compile(r"""(?:labelKey|shortLabelKey):\s*'((?:[^'\\]|\\.)*)'""")
INDIRECT = re.compile(r"""\bt\(\s*['"]learning['"]\s*,\s*([A-Za-z_][\w.]*)\s*\)""")

ESCAPE = re.compile(r"\\u([0-9a-fA-F]{4})|\\x([0-9a-fA-F]{2})|\\(.)")
SIMPLE = {"n": "\n", "t": "\t", "r": "\r", "\\": "\\", "'": "'", '"': '"',
          "/": "/", "0": "\0", "b": "\b", "f": "\f", "v": "\v"}


def unescape(text):
    def repl(m):
        if m.group(1):
            return chr(int(m.group(1), 16))
        if m.group(2):
            return chr(int(m.group(2), 16))
        ch = m.group(3)
        return SIMPLE.get(ch, ch)
    return ESCAPE.sub(repl, text)


def walk(rel, suffixes):
    base = os.path.join(APP, rel)
    if not os.path.isdir(base):
        return
    for dirpath, _, filenames in os.walk(base):
        if "node_modules" in dirpath:
            continue
        for name in filenames:
            if name.endswith(suffixes):
                yield os.path.join(dirpath, name)


def collect():
    literals = {}
    indirect = []

    def add(text, path):
        literals.setdefault(text, set()).add(os.path.relpath(path, ROOT))

    for path in walk("src", (".js", ".vue")):
        source = open(path, encoding="utf-8", errors="replace").read()
        for m in JS_T.finditer(source):
            add(unescape(m.group(2)), path)
        for m in JS_N.finditer(source):
            add(unescape(m.group(2)), path)
            add(unescape(m.group(4)), path)
        for m in INDIRECT.finditer(source):
            indirect.append((os.path.relpath(path, ROOT), m.group(1)))

    for rel in ("lib", "templates"):
        for path in walk(rel, (".php",)):
            source = open(path, encoding="utf-8", errors="replace").read()
            for m in PHP_T.finditer(source):
                add(unescape(m.group(1)), path)

    catalog = os.path.join(APP, "src", "utils", "toolCatalog.js")
    if os.path.isfile(catalog):
        source = open(catalog, encoding="utf-8").read()
        for m in TOOL_KEY.finditer(source):
            add(unescape(m.group(1)), catalog)

    return literals, indirect


def main():
    literals, indirect = collect()

    if "--list-indirect" in sys.argv:
        print("Unresolved indirect t() call sites (keys come from variables):")
        for path, expr in sorted(set(indirect)):
            print(f"  {path}: t('learning', {expr})")
        return 0

    de = json.load(open(f"{L10N}/de.json", encoding="utf-8"))["translations"]
    missing = sorted(k for k in literals if k not in de)

    if missing:
        print(f"FAIL: {len(missing)} translatable literal(s) missing from de.json.")
        print("They fall back to the source string and render in German in EVERY language.\n")
        for key in missing[:40]:
            where = ", ".join(sorted(literals[key])[:2])
            preview = key if len(key) <= 70 else key[:67] + "..."
            print(f"  {preview!r}\n      {where}")
        if len(missing) > 40:
            print(f"  ... and {len(missing) - 40} more")
        print("\nFix: add each key to app/l10n/de.json (and translate it in the other five),")
        print("then run: python3 scripts/l10n_js_sync.py && ./scripts/check-i18n-parity.sh")
        return 1

    print(f"i18n source coverage OK ({len(literals)} literals, all present in de.json)")
    return 0


if __name__ == "__main__":
    sys.exit(main())

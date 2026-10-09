#!/usr/bin/env python3
"""Guard: the AI consent text (app/data/ai-consent.json) is complete in all six languages.

The consent dialog renders the text for the user's language. A missing locale, a missing
paragraph or a dropped placeholder does not fail anywhere at runtime: the dialog falls back
or shows a gap, and the user consents to a text that does not name the recipient. The
general i18n gates do not see this file at all (it is not an l10n catalogue), so it gets
its own gate.

Checks, against the German source (`locales.de`):
  - top-level `version` is a non-empty string (ConsentService compares it as a string)
  - the locale set is exactly de/en/fr/ru/ar/uk
  - every locale has exactly the same key paths as de (missing and extra both fail)
  - no value is empty
  - per key path, the placeholder set ({provider}, {previous}, ...) equals de
  - placeholders only in plain-text fields, never in `*_html` (the recipient name contains an
    admin-entered host and would be substituted into v-html)
  - `*_html` fields carry the same tag sequence as de
  - prose is written in the expected script (ru/uk Cyrillic, ar Arabic); uk is not Russian
  - no provider that 5.7.0 does not offer is named (OpenAI, Anthropic, ...)

Usage:
    python3 scripts/check-consent-locales.py [path/to/ai-consent.json]   # exit 1 on any finding
"""
import json
import os
import re
import sys
import unicodedata

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DEFAULT_PATH = os.path.join(ROOT, "app", "data", "ai-consent.json")
LANGS = ["de", "en", "fr", "ru", "ar", "uk"]
SOURCE = "de"

PLACEHOLDER = re.compile(r"\{[a-zA-Z_]\w*\}")
TAG = re.compile(r"</?([a-zA-Z][a-zA-Z0-9]*)\b[^>]*>")

# Keys the dialog (Task D2) reads. A source text that loses one of them fails here, not in the UI.
REQUIRED_PATHS = [
    "title",
    "intro_html",
    "provider_sentence",
    "data_list_heading",
    "data_list_html",
    "identity_note",
    "region_paragraphs.us",
    "region_paragraphs.eu",
    "region_paragraphs.unknown",
    "region_paragraphs.local",
    "intermediary_paragraph",
    "automation_paragraph",
    "retention_note",
    "revocation_note",
    "reason_headers.version",
    "reason_headers.recipient_changed",
    "reason_headers.recipient_changed_unknown",
    "accept_label",
    "decline_label",
]

# Placeholders a field must carry (in every locale, via the parity check below).
REQUIRED_PLACEHOLDERS = {
    "provider_sentence": {"{provider}"},
    "region_paragraphs.us": {"{provider}"},
    "region_paragraphs.eu": {"{provider}"},
    "region_paragraphs.unknown": {"{provider}"},
    "region_paragraphs.local": {"{provider}"},
    "intermediary_paragraph": {"{provider}"},
    "automation_paragraph": {"{provider}"},
    "reason_headers.recipient_changed": {"{previous}", "{provider}"},
    "reason_headers.recipient_changed_unknown": {"{provider}"},
}

ALLOWED_PLACEHOLDERS = {"{provider}", "{previous}"}

# 5.7.0 offers Gemini and Ollama only (plan, scope cut 2026-10-09).
FORBIDDEN_NAMES = re.compile(r"openai|anthropic|claude|openrouter|mistral|ionos|deepseek|chatgpt", re.I)

EXPECTED_SCRIPT = {
    "de": "LATIN", "en": "LATIN", "fr": "LATIN",
    "ru": "CYRILLIC", "ar": "ARABIC", "uk": "CYRILLIC",
}
UKRAINIAN_ONLY = set("іїєґІЇЄҐ")
RUSSIAN_ONLY = set("ыэъЫЭЪ")


def flatten(node, prefix=""):
    out = {}
    if isinstance(node, dict):
        for key, value in node.items():
            out.update(flatten(value, f"{prefix}.{key}" if prefix else key))
    else:
        out[prefix] = node
    return out


def letters_by_script(text):
    counts = {"LATIN": 0, "CYRILLIC": 0, "ARABIC": 0}
    for ch in PLACEHOLDER.sub("", TAG.sub("", text)):
        if not ch.isalpha():
            continue
        try:
            name = unicodedata.name(ch)
        except ValueError:
            continue
        for script in counts:
            if script in name:
                counts[script] += 1
                break
    return counts


def check(path):
    findings = []
    try:
        with open(path, encoding="utf-8") as fh:
            data = json.load(fh)
    except (OSError, ValueError) as exc:
        return [f"cannot read {path}: {exc}"]

    version = data.get("version")
    if not isinstance(version, str) or not version.strip():
        findings.append("top-level 'version' must be a non-empty string")

    locales = data.get("locales")
    if not isinstance(locales, dict):
        return findings + ["missing object 'locales'"]

    if sorted(locales) != sorted(LANGS):
        missing = sorted(set(LANGS) - set(locales))
        extra = sorted(set(locales) - set(LANGS))
        findings.append(f"locale set differs: missing {missing}, extra {extra}")
    if SOURCE not in locales:
        return findings + [f"source locale '{SOURCE}' missing"]

    source = flatten(locales[SOURCE])
    for req in REQUIRED_PATHS:
        if req not in source:
            findings.append(f"{SOURCE}: required key '{req}' missing")
    for req, needed in REQUIRED_PLACEHOLDERS.items():
        if req in source and isinstance(source[req], str):
            lacking = needed - set(PLACEHOLDER.findall(source[req]))
            if lacking:
                findings.append(f"{SOURCE}.{req}: lacks placeholder(s) {sorted(lacking)}")

    for lang in LANGS:
        if lang not in locales:
            continue
        flat = flatten(locales[lang])
        for key in sorted(set(source) - set(flat)):
            findings.append(f"{lang}: missing key '{key}'")
        for key in sorted(set(flat) - set(source)):
            findings.append(f"{lang}: extra key '{key}'")
        for key, value in sorted(flat.items()):
            where = f"{lang}.{key}"
            if not isinstance(value, str) or not value.strip():
                findings.append(f"{where}: empty or not a string")
                continue
            found = set(PLACEHOLDER.findall(value))
            unknown = found - ALLOWED_PLACEHOLDERS
            if unknown:
                findings.append(f"{where}: unknown placeholder(s) {sorted(unknown)}")
            if key.endswith("_html") and found:
                findings.append(f"{where}: placeholder in an HTML field (would reach v-html)")
            if key in source and isinstance(source[key], str):
                expected = set(PLACEHOLDER.findall(source[key]))
                if found != expected:
                    findings.append(f"{where}: placeholders {sorted(found)} != {SOURCE} {sorted(expected)}")
                if key.endswith("_html"):
                    tags = [m.group(0)[:2] + m.group(1).lower() for m in TAG.finditer(value)]
                    want = [m.group(0)[:2] + m.group(1).lower() for m in TAG.finditer(source[key])]
                    if tags != want:
                        findings.append(f"{where}: HTML tag sequence differs from {SOURCE}")
            if FORBIDDEN_NAMES.search(value):
                findings.append(f"{where}: names a provider 5.7.0 does not offer")
            counts = letters_by_script(value)
            total = sum(counts.values())
            expected_script = EXPECTED_SCRIPT[lang]
            if total and counts[expected_script] < total * 0.6:
                findings.append(f"{where}: mostly not in {expected_script} script ({counts})")
            if lang != "ar" and counts["ARABIC"]:
                findings.append(f"{where}: Arabic letters in a non-Arabic locale")
            if lang in ("de", "en", "fr", "ar") and counts["CYRILLIC"]:
                findings.append(f"{where}: Cyrillic letters in a non-Cyrillic locale")

        if lang == "uk":
            prose = " ".join(v for v in flat.values() if isinstance(v, str))
            if not (set(prose) & UKRAINIAN_ONLY):
                findings.append("uk: no Ukrainian-only letter (і/ї/є/ґ) — looks like Russian")
            if set(prose) & RUSSIAN_ONLY:
                findings.append("uk: contains Russian-only letters (ы/э/ъ)")
        if lang == "ru":
            prose = " ".join(v for v in flat.values() if isinstance(v, str))
            if set(prose) & UKRAINIAN_ONLY:
                findings.append("ru: contains Ukrainian-only letters (і/ї/є/ґ)")

    return findings


def main(argv):
    path = argv[1] if len(argv) > 1 else DEFAULT_PATH
    findings = check(path)
    if findings:
        print(f"FAIL: consent text {os.path.relpath(path, ROOT)} ({len(findings)} finding(s))")
        for f in findings:
            print(f"  {f}")
        return 1
    with open(path, encoding="utf-8") as fh:
        n = len(flatten(json.load(fh)["locales"][SOURCE]))
    print(f"consent text OK across DE/EN/FR/RU/AR/UK ({n} fields each, placeholders identical)")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))

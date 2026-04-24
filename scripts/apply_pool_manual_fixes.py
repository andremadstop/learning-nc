#!/usr/bin/env python3
"""Hand-curated fixes for the 6 ambiguous_candidate / answer_still_too_long /
confidence_below_high / model_requested_manual_review items the automated
pipelines deferred to manual review. Each entry is a deliberate call I made
after reading the question context — the automated pipelines were right to
flag these, but with the question in hand the correct clean answer is
obvious.

Idempotent: we only update rows whose text still starts with the recorded
old-text prefix. Re-running is a no-op.

Leaves three genuinely ambiguous cases in manual_review for later human work:
 - 58261 (Pool 144): multi-line access-list config — possibly legitimate
 - 58798 (Pool 144): "SLEALE" = two collapsed terms (SLE + ALE), unclear which
   one is meant without external reference
 - 60675 (Pool 144): "Typosquatting Spear-phishing" — looks like two merged
   answer options, needs the source ODT to decide
"""

from __future__ import annotations

import json
import subprocess
import sys
from pathlib import Path

# answer_id → (new_text, old_prefix_to_match, explanation_to_append)
FIXES = {
    56084: ("Console", "ConsoleOut-Of-Band", "Out-Of-Band management – also jegliches Zugreifen außerhalb des produktiven Netzes. Crash cart"),
    59591: ("EDR", "EDR S.", "Future data loss nicht nur speziell bei Verlust des Devices, daher ist hier m.E. MDM als umfassendere Lösung."),
    59960: ("NIDS", "NIDS das Orchestration", "Orchestration in SOAR beschreibt, dass man in der Architektur redundante Prüfschritte unterbindet."),
    60520: ("Vulnerable software", "Vulnerable softwarefast", "Fast alles kann hier gemeint sein, für L2b wäre \"Default credentials\" plausibel (examtopics)."),
    60529: ("Inventory", "InventoryData retention", "Data retention = Vorratsdatenspeicherung ist das Bewahren der Transaktionsinformation."),
    60567: ("Enumeration", "EnumerationBE", "BE = Destruction würde das Zerstören des Massenspeichers bedeuten."),
}


def run_sql(sql: str, fetch: bool = False) -> str:
    result = subprocess.run(
        ["ssh", "relais", "docker", "exec", "-i", "devcloud-db",
         "psql", "-U", "oc_admin", "-d", "nextcloud",
         "-v", "ON_ERROR_STOP=1", "-X", "-A", "-t" if fetch else "-q"],
        input=sql, text=True, capture_output=True, check=False,
    )
    if result.returncode != 0:
        raise RuntimeError(f"psql failed: {result.stderr}\nSQL was:\n{sql}")
    return result.stdout


def esc(s: str) -> str:
    return s.replace("'", "''")


def main() -> int:
    dry_run = "--apply" not in sys.argv
    sql_parts = ["BEGIN;"]
    for aid, (new_text, prefix, explanation) in FIXES.items():
        new_answer = esc(new_text)
        prefix_like = esc(prefix) + "%"
        esc_expl = esc(explanation)
        sql_parts.append(
            f"WITH changed AS ("
            f"  UPDATE oc_learning_answers SET text = '{new_answer}' "
            f"  WHERE id = {aid} AND text LIKE '{prefix_like}' "
            f"  RETURNING question_id"
            f") "
            f"UPDATE oc_learning_questions q "
            f"SET explanation = CASE "
            f"  WHEN COALESCE(q.explanation, '') = '' THEN '{esc_expl}' "
            f"  ELSE q.explanation || E'\\n\\n---\\n\\n' || '{esc_expl}' "
            f"END FROM changed WHERE q.id IN (SELECT question_id FROM changed);"
        )
    sql_parts.append("COMMIT;")
    sql = "\n".join(sql_parts)

    if dry_run:
        print(f"=== DRY RUN — {len(FIXES)} manual fixes ===")
        for aid, (new_text, prefix, _expl) in FIXES.items():
            print(f"  answer {aid}: starts with {prefix!r} → {new_text!r}")
        print("\n(use --apply to execute)")
        return 0

    print(f"Executing {len(FIXES)} manual fixes in one transaction…")
    run_sql(sql)
    print("Done.")

    # Verify none remain in LIKE-prefix form
    for aid, (_nt, prefix, _e) in FIXES.items():
        stillmatches = run_sql(
            f"SELECT COUNT(*) FROM oc_learning_answers WHERE id = {aid} "
            f"AND text LIKE '{esc(prefix)}%';",
            fetch=True,
        ).strip()
        if stillmatches != "0":
            print(f"WARN: answer {aid} still matches old prefix — {stillmatches} rows")
    return 0


if __name__ == "__main__":
    sys.exit(main())

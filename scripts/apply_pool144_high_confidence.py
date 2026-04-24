#!/usr/bin/env python3
"""Apply the 33 high-confidence Pool-144 fixes that cleanup_pool144_answers.php
deferred to manual_review because of the cut_ratio_below_half rule. That rule
was wrong for this dataset: on Pool 144 the legitimate clean answer is almost
always much shorter than the original text (answer + instructor explanation),
so a high cut ratio is expected, not suspicious.

Safety net: each proposed_answer must literally appear as a substring inside
the original old_text before we accept it. That keeps the guard against Gemini
hallucinations intact without the over-aggressive ratio check.

Idempotent: re-running is safe — we only update rows where answers.text is
still equal to the old_text recorded in the report. Already-fixed rows skip.
"""

from __future__ import annotations

import json
import subprocess
import sys
from pathlib import Path

REPORT = Path(__file__).parent / "cleanup_pool144_report.json"
DRY_RUN = "--apply" not in sys.argv


def run_sql(sql: str, fetch: bool = False) -> str:
    cmd = [
        "ssh", "relais",
        "docker", "exec", "-i", "devcloud-db",
        "psql", "-U", "oc_admin", "-d", "nextcloud",
        "-v", "ON_ERROR_STOP=1",
        "-X", "-A", "-t" if fetch else "-q",
    ]
    result = subprocess.run(cmd, input=sql, text=True, capture_output=True, check=False)
    if result.returncode != 0:
        raise RuntimeError(f"psql failed: {result.stderr}\nSQL was:\n{sql}")
    return result.stdout


def sql_escape(s: str) -> str:
    return s.replace("'", "''")


def main() -> int:
    if not REPORT.exists():
        print(f"ERROR: report not found at {REPORT}", file=sys.stderr)
        return 1

    data = json.loads(REPORT.read_text())
    candidates = [
        c for c in data.get("manual_review", [])
        if c.get("reason") == "cut_ratio_below_half"
        and c.get("confidence") == "high"
        and c.get("proposed_answer")
    ]

    # Safety net: proposed_answer must literally appear inside the original text.
    safe = [c for c in candidates if c["proposed_answer"] in c["old_text"]]
    skipped = [c for c in candidates if c not in safe]

    print(f"High-confidence cut_ratio items: {len(candidates)}")
    print(f"  - substring safety net passes: {len(safe)}")
    print(f"  - hallucination-suspects (skipped): {len(skipped)}")
    if skipped:
        print("  Skipped IDs:", [c["answer_id"] for c in skipped])

    if not safe:
        print("Nothing to do.")
        return 0

    if DRY_RUN:
        print("\n=== DRY RUN — first 5 proposed updates ===")
        for c in safe[:5]:
            print(f"  answer {c['answer_id']}: {c['old_text'][:60]!r} → {c['proposed_answer']!r}")
        print(f"\n(use --apply to execute {len(safe)} updates)")
        return 0

    # Build one SQL transaction. Each pair is idempotent via CTE:
    #   UPDATE answer only when text still matches old_text → RETURNING row,
    #   then UPDATE question's explanation only when the answer update fired.
    # Re-running after a successful apply becomes a no-op, because the
    # answer's text no longer matches old_text, the CTE returns zero rows,
    # and the second UPDATE has no input to work on.
    sql_parts = ["BEGIN;"]
    for c in safe:
        aid = int(c["answer_id"])
        qid = int(c["question_id"])
        new_answer = sql_escape(c["proposed_answer"])
        old_text = sql_escape(c["old_text"])
        explanation = c.get("proposed_explanation") or ""

        if explanation:
            esc_expl = sql_escape(explanation)
            sql_parts.append(
                f"WITH changed AS ("
                f"  UPDATE oc_learning_answers SET text = '{new_answer}' "
                f"  WHERE id = {aid} AND text = '{old_text}' "
                f"  RETURNING question_id"
                f") "
                f"UPDATE oc_learning_questions q "
                f"SET explanation = CASE "
                f"  WHEN COALESCE(q.explanation, '') = '' THEN '{esc_expl}' "
                f"  ELSE q.explanation || E'\\n\\n---\\n\\n' || '{esc_expl}' "
                f"END FROM changed WHERE q.id = {qid} AND changed.question_id IS NOT NULL;"
            )
        else:
            sql_parts.append(
                f"UPDATE oc_learning_answers SET text = '{new_answer}' "
                f"WHERE id = {aid} AND text = '{old_text}';"
            )
    sql_parts.append("COMMIT;")

    sql = "\n".join(sql_parts)
    print(f"\nExecuting {len(safe)} answer updates + explanation appends as one transaction…")
    run_sql(sql)

    # Verify
    verify_sql = (
        "SELECT COUNT(*) FROM oc_learning_answers a "
        "JOIN oc_learning_questions q ON q.id = a.question_id "
        "WHERE q.pool_id = 144 AND LENGTH(a.text) > 80;"
    )
    remaining = run_sql(verify_sql, fetch=True).strip()
    print(f"Pool 144 answers with LENGTH(text) > 80 remaining: {remaining}")
    return 0


if __name__ == "__main__":
    sys.exit(main())

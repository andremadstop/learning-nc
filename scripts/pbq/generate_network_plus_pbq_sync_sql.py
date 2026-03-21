#!/usr/bin/env python3
import argparse
import json
from pathlib import Path


def sql_literal(value: str) -> str:
    return "'" + value.replace("'", "''") + "'"


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Generate UPDATE statements for the audited Network+ PBQs."
    )
    parser.add_argument(
        "--source",
        default="pbq-import-n10009.json",
        help="Path to the canonical PBQ JSON source file.",
    )
    args = parser.parse_args()

    source_path = Path(args.source)
    data = json.loads(source_path.read_text(encoding="utf-8"))

    print("BEGIN;")
    for item in data:
        if item.get("type") != "pbq" or item.get("db_id") is None:
            continue

        config_json = json.dumps(item["pbq_config"], ensure_ascii=False)
        explanation = item.get("explanation", "")
        difficulty = item.get("difficulty", "medium")

        print(
            "UPDATE oc_learning_questions\n"
            "SET text = {text},\n"
            "    explanation = {explanation},\n"
            "    pbq_subtype = {subtype},\n"
            "    pbq_config = {config},\n"
            "    difficulty = {difficulty},\n"
            "    review_status = 'published'\n"
            "WHERE id = {db_id};".format(
                text=sql_literal(item["question_text"]),
                explanation=sql_literal(explanation),
                subtype=sql_literal(item["subtype"]),
                config=sql_literal(config_json),
                difficulty=sql_literal(difficulty),
                db_id=int(item["db_id"]),
            )
        )
    print("COMMIT;")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

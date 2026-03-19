#!/usr/bin/env python3
import argparse
import json
import time
from pathlib import Path


def sql_literal(value):
    if value is None:
        return 'NULL'
    return "'" + str(value).replace("'", "''") + "'"


def chunked(items, size):
    for index in range(0, len(items), size):
        yield items[index:index + size]


def build_question_rows(questions, lang, created_at):
    rows = []
    for question in questions:
        rows.append(
            "(" + ", ".join([
                str(int(question['question_id'])),
                sql_literal(lang),
                sql_literal(question.get('translated_text', '')),
                sql_literal(question.get('translated_explanation')),
                str(created_at),
            ]) + ")"
        )
    return rows


def build_answer_rows(questions, lang, created_at):
    rows = []
    for question in questions:
        for answer in question.get('answers', []):
            rows.append(
                "(" + ", ".join([
                    str(int(answer['answer_id'])),
                    sql_literal(lang),
                    sql_literal(answer.get('translated_text', '')),
                    str(created_at),
                ]) + ")"
            )
    return rows


def emit_upserts(table, columns, conflict_columns, update_columns, rows):
    statements = []
    for chunk in chunked(rows, 250):
        statements.append(
            f"INSERT INTO {table} ({', '.join(columns)})\nVALUES\n  " +
            ",\n  ".join(chunk) +
            "\nON CONFLICT (" + ", ".join(conflict_columns) + ") DO UPDATE SET\n  " +
            ",\n  ".join(f"{column} = EXCLUDED.{column}" for column in update_columns) +
            ";"
        )
    return "\n\n".join(statements)


def main():
    parser = argparse.ArgumentParser(description='Convert translation JSON into PostgreSQL UPSERT SQL.')
    parser.add_argument('input_json', type=Path)
    parser.add_argument('--prefix', default='oc_', help='Nextcloud table prefix, default: oc_')
    parser.add_argument('--lang', default='', help='Override language code from JSON meta')
    parser.add_argument('--created-at', type=int, default=int(time.time()))
    args = parser.parse_args()

    data = json.load(open(args.input_json, encoding='utf-8'))
    lang = args.lang or data.get('meta', {}).get('lang', '')
    if not lang:
        raise SystemExit('Missing language code. Provide --lang or meta.lang in JSON.')

    questions = data.get('questions', [])
    if not questions:
        raise SystemExit('No questions found in input JSON.')

    q_rows = build_question_rows(questions, lang, args.created_at)
    a_rows = build_answer_rows(questions, lang, args.created_at)
    prefix = args.prefix

    print('BEGIN;')
    print()
    print(emit_upserts(
        f'{prefix}learning_qst_translations',
        ['question_id', 'lang', 'text', 'explanation', 'created_at'],
        ['question_id', 'lang'],
        ['text', 'explanation', 'created_at'],
        q_rows,
    ))
    print()
    print(emit_upserts(
        f'{prefix}learning_ans_translations',
        ['answer_id', 'lang', 'text', 'created_at'],
        ['answer_id', 'lang'],
        ['text', 'created_at'],
        a_rows,
    ))
    print()
    print('COMMIT;')


if __name__ == '__main__':
    main()

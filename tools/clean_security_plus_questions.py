#!/usr/bin/env python3
from __future__ import annotations

import argparse
import hashlib
import json
import pathlib
import re
from collections import defaultdict


DEFAULT_INPUT = pathlib.Path.home() / 'Workspace/Lokal/comptia-extraktion/comptia-security-plus/00-Buchfragen.json'
DEFAULT_OUTPUT = DEFAULT_INPUT.with_name('00-Buchfragen-clean.json')

NOISE_PATTERNS = [
    r'LICENSED FOR USE ONLY BY:[^\n]*',
    r'SY0-701_[^\n]+',
    r'Lesson \d+\s*\|[^\n]*',
    r'Appendix A\s*\|[^\n]*',
    r'Solutions?\s+\|[^\n]*',
]

CHOICE_KEYS = ['A', 'B', 'C', 'D']


def clean_text(raw: object) -> str:
    text = str(raw or '').replace('\x0c', ' ')
    for pattern in NOISE_PATTERNS:
        text = re.sub(pattern, ' ', text, flags=re.IGNORECASE)

    text = re.sub(r'\s+', ' ', text).strip()
    return text


def clean_question(raw: object) -> str:
    question = clean_text(raw)
    question = re.sub(r'^\d+[\).:]\s*', '', question)
    question = re.sub(r'\s+\d+[\).:]\s*$', '', question)
    return question.strip()


def extract_answer(raw: object) -> str:
    answer = clean_text(raw)
    answer = re.sub(r'^\d+[\).:]\s*', '', answer)
    answer = re.sub(r'^Answers? will vary\.?\s*', '', answer, flags=re.IGNORECASE)

    if answer == '':
        return ''

    parts = re.split(r'(?<=[.!?])\s+', answer)
    candidate = parts[0].strip() if parts else answer
    if len(candidate) > 180:
        candidate = candidate[:180].rsplit(' ', 1)[0].strip()

    return candidate.strip(' .')


def is_usable_question(question: str, answer: str) -> bool:
    if len(question) < 20 or len(answer) < 2:
        return False
    if 'LICENSED FOR USE ONLY' in question or 'LICENSED FOR USE ONLY' in answer:
        return False
    if answer.lower().startswith('lesson ') or answer.lower().startswith('appendix '):
        return False
    if question == answer:
        return False
    return True


def ranked_candidates(question: str, candidates: list[str]) -> list[str]:
    return sorted(
        candidates,
        key=lambda candidate: hashlib.sha256(f'{question}::{candidate}'.encode('utf-8')).hexdigest(),
    )


def pick_distractors(question: str, theme: str, correct_answer: str, per_theme: dict[str, set[str]], all_answers: set[str]) -> list[str]:
    themed_pool = ranked_candidates(
        question,
        [answer for answer in per_theme.get(theme, set()) if answer != correct_answer],
    )

    distractors: list[str] = []
    for candidate in themed_pool:
        if candidate not in distractors:
            distractors.append(candidate)
        if len(distractors) == 3:
            return distractors

    global_pool = ranked_candidates(
        question,
        [answer for answer in all_answers if answer != correct_answer and answer not in distractors],
    )

    for candidate in global_pool:
        if candidate not in distractors:
            distractors.append(candidate)
        if len(distractors) == 3:
            break

    return distractors


def build_question_record(question: str, correct_answer: str, distractors: list[str]) -> dict[str, str]:
    options = [correct_answer, *distractors[:3]]
    option_order = ranked_candidates(question + '::options', options)

    record = {'Frage': question}
    correct_key = 'A'
    for key, option in zip(CHOICE_KEYS, option_order):
        record[key] = option
        if option == correct_answer:
            correct_key = key

    record['Lösung'] = correct_key
    return record


def load_source_items(path: pathlib.Path) -> list[dict]:
    data = json.loads(path.read_text(encoding='utf-8'))
    if not isinstance(data, list):
        raise ValueError(f'Expected a JSON list in {path}')
    return [item for item in data if isinstance(item, dict)]


def convert_items(items: list[dict]) -> tuple[list[dict[str, str]], dict[str, int]]:
    normalized_rows: list[tuple[str, str, str]] = []
    stats = {
        'total': len(items),
        'usable_source_rows': 0,
        'skipped_invalid': 0,
        'skipped_missing_distractors': 0,
    }

    for item in items:
        theme = clean_text(item.get('Thema', 'Allgemein')) or 'Allgemein'
        question = clean_question(item.get('Frage', ''))
        answer = extract_answer(item.get('Lösung', ''))

        if not is_usable_question(question, answer):
            stats['skipped_invalid'] += 1
            continue

        normalized_rows.append((theme, question, answer))

    stats['usable_source_rows'] = len(normalized_rows)

    per_theme: dict[str, set[str]] = defaultdict(set)
    all_answers: set[str] = set()
    for theme, _, answer in normalized_rows:
        per_theme[theme].add(answer)
        all_answers.add(answer)

    converted: list[dict[str, str]] = []
    for theme, question, answer in normalized_rows:
        distractors = pick_distractors(question, theme, answer, per_theme, all_answers)
        if len(distractors) < 3:
            stats['skipped_missing_distractors'] += 1
            continue

        converted.append(build_question_record(question, answer, distractors))

    return converted, stats


def main() -> int:
    parser = argparse.ArgumentParser(description='Clean and normalize Security+ source questions into 4-option MCQ JSON.')
    parser.add_argument('--input', type=pathlib.Path, default=DEFAULT_INPUT)
    parser.add_argument('--output', type=pathlib.Path, default=DEFAULT_OUTPUT)
    args = parser.parse_args()

    items = load_source_items(args.input)
    converted, stats = convert_items(items)

    args.output.write_text(
        json.dumps(converted, ensure_ascii=False, indent=2) + '\n',
        encoding='utf-8',
    )

    print(f'Input rows: {stats["total"]}')
    print(f'Usable source rows: {stats["usable_source_rows"]}')
    print(f'Skipped invalid rows: {stats["skipped_invalid"]}')
    print(f'Skipped for missing distractors: {stats["skipped_missing_distractors"]}')
    print(f'Written clean questions: {len(converted)}')
    print(f'Output: {args.output}')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())

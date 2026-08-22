#!/usr/bin/env python3
"""
Check every alias-qualified SQL column reference in app/lib against the live database schema.

Why this exists
---------------
Three separate outages were found by running this once, on 2026-08-22:

  * LernprofilService      c.name   on learning_courses      (has title)   — broke /api/profile/skill-map
                                                                             from 2026-04-09 on
  * RagContextService      ua.user_id on learning_user_answers (has session_id) — user weaknesses
                                                                             never loaded
  * CourseSummaryService   ua.user_id, plus `is_correct = 1` on a boolean column — trouble spots
                                                                             never loaded

None of them was visible to PHPStan (the PHP is well-formed; the defect is in a string), and all
three sat behind a `catch (\\Throwable)` that returned a 500 or an empty list. Two of the three were
additionally covered by an API test that accepted "200 OR 500".

What it does
------------
Builds an alias -> table map per file from `->from('table','alias')` and `->*Join(..., 'table',
'alias', ...)`, then resolves every `alias.column` occurrence — including raw SQL inside
createFunction()/having()/orderBy() — against information_schema of the running instance.

Usage
-----
    python3 scripts/check-sql-columns.py                 # uses ssh relais + devcloud-db
    SCHEMA_FILE=schema.txt python3 scripts/check-sql-columns.py   # offline, pre-dumped schema

Dump format for SCHEMA_FILE (one per line, no oc_ prefix needed — it is stripped):
    oc_learning_courses|title

Two shapes are checked
----------------------
  1. Alias-qualified references (`alias.column`) resolved through the file's from()/join() map.
  2. Unaliased single-table queries — `select('a','b')->from('table')` with no alias argument.
     Leaving this out is what let `chapter_ref` on learning_pools and `name` on learning_courses
     survive the first run of this script.

Limitations (deliberate, not oversights)
----------------------------------------
  * Unqualified columns in where()/orderBy() of unaliased queries are not resolved — they are not
    distinguishable from expression aliases without parsing the SQL.
  * The alias map is per FILE, not per method. A file that reuses one alias for two different
    tables can produce a false positive — read the finding before believing it.
  * Pure comment lines are skipped (they describe past bugs by name, e.g. "use q.text, not
    q.question"). A reference inside a trailing comment on a code line is still reported — every
    hit is a lead, not a verdict.

Exit codes: 0 = no findings, 1 = findings, 2 = schema unavailable.
"""
import re
import os
import subprocess
import sys
import collections

SQL_KEYWORDS = {
    'count', 'sum', 'max', 'min', 'avg', 'case', 'when', 'then', 'else', 'end', 'as', 'and', 'or',
    'not', 'null', 'true', 'false', 'distinct', 'coalesce', 'cast', 'int', 'integer', 'desc',
    'asc', 'on', 'is', 'in', 'like',
}

SCHEMA_QUERY = (
    "select table_name, column_name from information_schema.columns "
    "where table_name like 'oc_learning%' order by table_name, column_name"
)


def load_schema():
    """table (without oc_ prefix) -> set(columns)."""
    dump = os.environ.get('SCHEMA_FILE')
    if dump:
        raw = open(dump, encoding='utf-8').read()
    else:
        try:
            raw = subprocess.run(
                ['ssh', '-o', 'ConnectTimeout=5', 'relais',
                 f'docker exec devcloud-db psql -U oc_admin -d nextcloud -tAF"|" -c "{SCHEMA_QUERY}"'],
                capture_output=True, text=True, timeout=60, check=True,
            ).stdout
        except (subprocess.CalledProcessError, subprocess.TimeoutExpired, FileNotFoundError) as err:
            print(f'SKIP: database schema unavailable ({err.__class__.__name__})', file=sys.stderr)
            sys.exit(2)

    schema = collections.defaultdict(set)
    for line in raw.splitlines():
        line = line.strip()
        if '|' not in line:
            continue
        table, column = line.split('|', 1)
        schema[table.replace('oc_', '', 1)].add(column)
    if not schema:
        print('SKIP: schema query returned nothing', file=sys.stderr)
        sys.exit(2)
    return schema


def unaliased_select_columns(source):
    """Yield (table, column, position) for `select('a', 'b')->from('table')` with no alias.

    The negative lookahead on the from() argument is what distinguishes this from an aliased
    query — `from('t', 'x')` is handled by the alias map instead.
    """
    pattern = re.compile(
        r"->select\(\s*((?:['\"][a-z_0-9]+['\"]\s*,?\s*)+)\)\s*"
        r"->from\(\s*['\"]([a-z_0-9]+)['\"]\s*\)",
        re.S,
    )
    for match in pattern.finditer(source):
        columns, table = match.group(1), match.group(2)
        for col in re.finditer(r"['\"]([a-z_0-9]+)['\"]", columns):
            yield table, col.group(1), match.start() + col.start()


def alias_map(source):
    aliases = {}
    for match in re.finditer(r"->from\(\s*['\"]([a-z_0-9]+)['\"]\s*,\s*['\"]([a-zA-Z_0-9]+)['\"]", source):
        aliases[match.group(2)] = match.group(1)
    for match in re.finditer(
        r"->(?:innerJoin|leftJoin|rightJoin)\(\s*['\"][^'\"]*['\"]\s*,\s*['\"]([a-z_0-9]+)['\"]\s*,\s*['\"]([a-zA-Z_0-9]+)['\"]",
        source,
    ):
        aliases[match.group(2)] = match.group(1)
    return aliases


def main():
    schema = load_schema()
    root = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'app', 'lib')
    findings = []
    checked_files = 0

    for dirpath, _, filenames in os.walk(root):
        for filename in filenames:
            if not filename.endswith('.php'):
                continue
            path = os.path.join(dirpath, filename)
            source = open(path, encoding='utf-8').read()
            aliases = alias_map(source)
            if not aliases:
                continue
            checked_files += 1
            seen = set()

            for table, column, pos in unaliased_select_columns(source):
                if table not in schema or column in schema[table]:
                    continue
                line_no = source[:pos].count('\n') + 1
                line = source.splitlines()[line_no - 1].strip()
                if line.startswith(('//', '*', '/*', '#')):
                    continue
                findings.append((os.path.relpath(path), line_no, f'{table}.{column}', table, line))

            for match in re.finditer(r"\b([a-zA-Z_][a-zA-Z_0-9]*)\.([a-z_][a-z_0-9]*)\b", source):
                alias, column = match.group(1), match.group(2)
                if alias not in aliases or column in SQL_KEYWORDS:
                    continue
                table = aliases[alias]
                if table not in schema or column in schema[table]:
                    continue
                line_no = source[:match.start()].count('\n') + 1
                line = source.splitlines()[line_no - 1].strip()
                # Skip pure comment lines BEFORE the dedup bookkeeping: several comments name a
                # wrong column on purpose, to record which mistake was made there. Marking those
                # as seen would swallow the real occurrence further down the same file — which is
                # exactly what happened when this check was first written, and it reported clean
                # on a file that still had the bug.
                if line.startswith(('//', '*', '/*', '#')):
                    continue
                # Keyed by LINE, not just by file: keying per file hid a second copy of the very
                # same broken query further down LernprofilService, which only surfaced after the
                # first one was fixed. Report every site.
                key = (path, line_no, alias, column)
                if key in seen:
                    continue
                seen.add(key)
                findings.append((os.path.relpath(path), line_no, f'{alias}.{column}', table, line))

    print(f'Checked {checked_files} files with query builders against {len(schema)} learning tables.')
    if not findings:
        print('No column references outside the schema.')
        return 0

    print(f'\n{len(findings)} reference(s) not found in the schema:\n')
    for path, line_no, ref, table, line in findings:
        print(f'  {path}:{line_no}  {ref}  — {table} has no such column')
        print(f'      {line[:120]}')
        print(f'      columns: {", ".join(sorted(schema[table])[:12])}')
    print('\nA hit inside a comment is expected and harmless — check the line before acting.')
    return 1


if __name__ == '__main__':
    sys.exit(main())

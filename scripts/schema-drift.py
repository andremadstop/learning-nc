#!/usr/bin/env python3
"""
Compare the learning tables of a live instance with a fresh schema and with the entities in lib/Db.

Why this exists
---------------
5.14.1: installs that started on 1.x/2.x still had the column learning_pools.visibility. 5.11.0
removed the Pool entity's `visibility` property; PoolMapper selects '*', and Nextcloud's
Entity::fromRow() throws "visibility is not a valid attribute" for every column without a
property. Every full pool read failed on those installs for three releases. No probe saw it: they
all start from a fresh install or a 5.5.1 release, and neither has the column.

This script only reads two text files and the PHP sources; it never connects to a database.
Run by scripts/schema-drift.sh, which documents how to produce the live file.

Usage
-----
    python3 scripts/schema-drift.py --live <file> --fresh <file> [--lib <app/lib>]
    python3 scripts/schema-drift.py --list [--lib <app/lib>]    # mapper|table|entity|SELECT * methods

Both files hold one `table|column` per line (a tab instead of `|` is accepted). Any table prefix
before `learning_` is stripped and names are lowercased, so `oc_learning_pools`, `nc_learning_pools`
and `learning_pools` are the same table.

Findings
--------
  COLUMN WITHOUT PROPERTY   live column on a table an entity maps, and the entity has no property
                            for it. Every hydrating SELECT * on that table throws.        -> exit 1
  MISSING TABLE (entity)    an entity's table exists in the fresh schema but not live.    -> exit 1
  MISSING COLUMN            a column of the fresh schema is absent from a live table: a
                            migration did not run (5.13.0 -> 5.14.0 without a version bump).
                                                                                          -> exit 1
  MISSING TABLE (used)      a fresh table without entity is absent live, and code outside
                            lib/Migration and the uninstall command names it.             -> exit 1
  MISSING TABLE             a fresh table no code uses any more is absent live (the legacy
                            learning_q_translations after Version007900 renamed it).      -> note
  EXTRA COLUMN              live-only column; with or without entity property.            -> note
  EXTRA TABLE               live-only table.                                              -> note
  NAME?                     a live-only table or column that resembles a fresh-only one
                            (q_translations / qst_translations).                          -> note
  PROPERTY WITHOUT COLUMN   entity property with no column live (Pool::$visibility on a fresh
                            install is the intended case: read and ignored).              -> note

Exit codes: 0 = no breaking drift, 1 = breaking drift, 2 = input missing, empty or unreadable.
"""
import argparse
import difflib
import os
import re
import sys

MAPPER_RE = re.compile(
    r"parent::__construct\(\s*\$\w+\s*,\s*['\"](learning_\w+)['\"]\s*,\s*([\w\\]+)::class"
)
# protected $x;  protected ?string $x = null;  public int $x = 0;  (never static)
PROP_RE = re.compile(
    r"^\s*(?:protected|public|private)\s+(?!static\b|function\b|const\b)(?:\??[\w\\|]+\s+)?\$(\w+)\s*[;=]",
    re.M,
)
FUNC_RE = re.compile(r"function\s+(\w+)\s*\(")
STAR_RE = re.compile(r"->select\(\s*['\"](?:\w+\.)?\*['\"]\s*\)")


def die(msg):
    print(f"schema-drift: {msg}", file=sys.stderr)
    sys.exit(2)


def norm_table(name):
    name = name.strip().strip('"`').lower()
    i = name.find("learning_")
    return name[i:] if i >= 0 else None


def read_schema(path, label):
    if not path or not os.path.isfile(path):
        die(f"{label} file missing: {path!r}")
    try:
        with open(path, encoding="utf-8") as fh:
            lines = fh.read().splitlines()
    except OSError as exc:
        die(f"{label} file unreadable: {exc}")
    tables = {}
    for raw in lines:
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        parts = re.split(r"\s*[|\t]\s*", line)
        if len(parts) < 2 or not parts[1]:
            continue
        table = norm_table(parts[0])
        if table is None:
            continue
        tables.setdefault(table, set()).add(parts[1].strip().strip('"`').lower())
    if not tables:
        die(f"{label} file has no learning_* table|column lines: {path}")
    return tables


def column_to_property(column):
    """Nextcloud's Entity::columnToProperty(): snake_case -> camelCase."""
    parts = column.split("_")
    return parts[0] + "".join(p[:1].upper() + p[1:] for p in parts[1:])


def read_entities(lib):
    """table -> {entity, properties, star_methods} from every QBMapper in lib/Db."""
    db_dir = os.path.join(lib, "Db")
    if not os.path.isdir(db_dir):
        die(f"no Db directory in {lib!r}")
    mapped = {}
    for name in sorted(os.listdir(db_dir)):
        if not name.endswith("Mapper.php"):
            continue
        with open(os.path.join(db_dir, name), encoding="utf-8") as fh:
            src = fh.read()
        m = MAPPER_RE.search(src)
        if not m:
            continue
        table, entity = m.group(1).lower(), m.group(2).rsplit("\\", 1)[-1]
        entity_file = os.path.join(db_dir, entity + ".php")
        if not os.path.isfile(entity_file):
            die(f"{name} maps {table} to {entity}, but {entity}.php does not exist")
        with open(entity_file, encoding="utf-8") as fh:
            esrc = fh.read()
        props = set(PROP_RE.findall(esrc)) | {"id"}  # id is declared by OCP\AppFramework\Db\Entity
        star = []
        funcs = list(FUNC_RE.finditer(src))
        for i, f in enumerate(funcs):
            end = funcs[i + 1].start() if i + 1 < len(funcs) else len(src)
            if STAR_RE.search(src[f.end():end]):
                star.append(f.group(1))
        mapped[table] = {"entity": entity, "mapper": name[:-4], "props": props, "star": star}
    if not mapped:
        die(f"no QBMapper with a learning_* table found in {db_dir}")
    return mapped


TABLE_LITERAL_RE = re.compile(r"['\"](learning_\w+)['\"]")


def tables_used(lib):
    """table -> files that name it, outside lib/Migration and the uninstall command (which list
    legacy tables only to create, rename or drop them)."""
    used = {}
    for root, dirs, files in os.walk(lib):
        dirs[:] = [d for d in dirs if not (root == lib and d == "Migration")]
        for name in files:
            if not name.endswith(".php") or name == "UninstallCommand.php":
                continue
            path = os.path.join(root, name)
            with open(path, encoding="utf-8") as fh:
                for t in TABLE_LITERAL_RE.findall(fh.read()):
                    used.setdefault(t.lower(), set()).add(os.path.relpath(path, lib))
    return used


def similar(a, b):
    return difflib.SequenceMatcher(None, a, b).ratio()


def main():
    ap = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    ap.add_argument("--live")
    ap.add_argument("--fresh")
    ap.add_argument("--list", action="store_true", help="list every mapper method that selects '*' and exit")
    ap.add_argument("--lib", default=os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "app", "lib"))
    args = ap.parse_args()

    if args.list:
        for table, e in sorted(read_entities(args.lib).items(), key=lambda kv: kv[1]["mapper"]):
            print(f"{e['mapper']}|{table}|{e['entity']}|{','.join(e['star'])}")
        return 0
    if not args.live or not args.fresh:
        die("--live and --fresh are required (or --list)")

    live = read_schema(args.live, "live")
    fresh = read_schema(args.fresh, "fresh")
    entities = read_entities(args.lib)
    used = tables_used(args.lib)

    breaking, notes = [], []

    for table in sorted(set(live) | set(fresh)):
        ent = entities.get(table)
        lcols, fcols = live.get(table), fresh.get(table)
        if lcols is None:
            if ent:
                breaking.append(f"MISSING TABLE (entity {ent['entity']}): {table}")
            elif table in used:
                breaking.append(f"MISSING TABLE (used in {', '.join(sorted(used[table])[:3])}): {table}")
            else:
                notes.append(f"MISSING TABLE: {table} (fresh only, no code outside migrations names it)")
            continue
        if fcols is None:
            notes.append(f"EXTRA TABLE: {table} (live only{', entity ' + ent['entity'] if ent else ''})")
            fcols = set()
        for col in sorted(lcols - fcols):
            if not fcols:
                break
            has = ent and column_to_property(col) in ent["props"]
            if ent and not has:
                continue  # reported below as COLUMN WITHOUT PROPERTY
            what = f"entity {ent['entity']} has ${column_to_property(col)}" if ent else "no entity"
            notes.append(f"EXTRA COLUMN: {table}.{col} (live only, {what})")
        for col in sorted(fcols - lcols):
            breaking.append(f"MISSING COLUMN: {table}.{col} (in the fresh schema, not live — a migration did not run)")
        if ent:
            for col in sorted(lcols):
                prop = column_to_property(col)
                if prop not in ent["props"]:
                    star = ", ".join(ent["star"]) or "none"
                    origin = "also in the fresh schema" if col in fcols else "live only"
                    breaking.append(
                        f"COLUMN WITHOUT PROPERTY: {table}.{col} -> {ent['entity']}::${prop} missing "
                        f"({origin}; SELECT * in {ent['mapper']}: {star})"
                    )
            for prop in sorted(ent["props"] - {column_to_property(c) for c in lcols}):
                notes.append(f"PROPERTY WITHOUT COLUMN: {ent['entity']}::${prop} (no column in live {table})")

    # Name deviations: live-only tables/columns that resemble fresh-only ones.
    live_only = sorted(set(live) - set(fresh))
    fresh_only = sorted(set(fresh) - set(live))
    for lt in live_only:
        best = max(fresh_only, key=lambda ft: (len(live[lt] & fresh[ft]) / max(1, len(live[lt] | fresh[ft])), similar(lt, ft)), default=None)
        if best and len(live[lt] & fresh[best]) / max(1, len(live[lt] | fresh[best])) >= 0.5:
            notes.append(f"NAME? table {lt} (live) looks like {best} (fresh)")
    for table in sorted(set(live) & set(fresh)):
        lo, fo = sorted(live[table] - fresh[table]), sorted(fresh[table] - live[table])
        for lc in lo:
            match = max(fo, key=lambda fc: similar(lc, fc), default=None)
            if match and similar(lc, match) >= 0.7:
                notes.append(f"NAME? column {table}.{lc} (live) looks like {table}.{match} (fresh)")

    star_total = sum(len(e["star"]) for e in entities.values())
    print(f"schema-drift: live {len(live)} tables / {sum(map(len, live.values()))} columns, "
          f"fresh {len(fresh)} / {sum(map(len, fresh.values()))}, "
          f"{len(entities)} entities, {star_total} mapper methods with SELECT *")
    for line in breaking:
        print(f"  BREAKING  {line}")
    for line in notes:
        print(f"  note      {line}")
    if breaking:
        print(f"schema-drift: {len(breaking)} breaking finding(s)")
        return 1
    print("schema-drift: no breaking drift")
    return 0


if __name__ == "__main__":
    sys.exit(main())

#!/usr/bin/env python3
"""check-node-modules.py <package-lock.json> <node_modules dir>

Exit 0 only if the installed tree matches the lockfile: every non-optional package the
lockfile lists is installed at exactly the locked version (npm records the installed tree in
node_modules/.package-lock.json). Matching lockfiles alone prove nothing when node_modules was
installed from an earlier checkout, and the hooks run the snapshot against this directory.
"""
import json
import os
import sys


def main():
    if len(sys.argv) != 3:
        print(__doc__.strip().splitlines()[0], file=sys.stderr)
        return 2
    lock_path, modules = sys.argv[1], sys.argv[2]
    hidden_path = os.path.join(modules, '.package-lock.json')
    try:
        with open(lock_path, encoding='utf-8') as fh:
            locked = json.load(fh).get('packages', {})
        with open(hidden_path, encoding='utf-8') as fh:
            installed = json.load(fh).get('packages', {})
    except (OSError, ValueError) as err:
        print(f'node_modules check: cannot read lock data ({err.__class__.__name__}: {err})', file=sys.stderr)
        return 2
    if not locked:
        print(f'node_modules check: {lock_path} lists no packages', file=sys.stderr)
        return 2

    problems = []
    for name, meta in locked.items():
        if not name.startswith('node_modules/'):
            continue
        have = installed.get(name)
        if have is None:
            # Optional (platform-specific) and peer packages are legitimately absent.
            if not (meta.get('optional') or meta.get('devOptional') or meta.get('peer')):
                problems.append(f'{name} missing (locked {meta.get("version")})')
            continue
        if have.get('version') != meta.get('version'):
            problems.append(f'{name} installed {have.get("version")}, locked {meta.get("version")}')
    for name in installed:
        if name.startswith('node_modules/') and name not in locked:
            problems.append(f'{name} installed but not in the lockfile')

    if problems:
        print(f'node_modules does not match {lock_path} ({len(problems)} difference(s)) — run npm ci:', file=sys.stderr)
        for p in problems[:10]:
            print(f'  {p}', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())

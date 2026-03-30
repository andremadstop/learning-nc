#!/usr/bin/env python3
"""
Security check: flag v-html usage not approved by eslint-disable-next-line vue/no-v-html.
Exit 1 if violations found, 0 if clean.
"""
import glob
import sys

violations = []
for f in glob.glob('app/src/**/*.vue', recursive=True):
    lines = open(f).readlines()
    for i, line in enumerate(lines):
        if ' v-html' in line and 'eslint-disable' not in line:
            prev = lines[i - 1] if i > 0 else ''
            if 'eslint-disable-next-line' not in prev or 'vue/no-v-html' not in prev:
                violations.append(f'{f}:{i + 1}: {line.rstrip()}')

for v in violations:
    print(v)

if violations:
    print('FAIL: v-html found without explicit security approval (XSS risk)')
    sys.exit(1)

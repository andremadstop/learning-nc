#!/usr/bin/env python3
"""check-php-coverage.py — PHP coverage must not fall below the floor.

Usage: scripts/check-php-coverage.py [app/coverage/php/clover.xml]

Reads the project totals of the clover report that `scripts/gate-container.sh coverage` writes and
fails when line or method coverage of app/lib is below the floor. The floor is the coverage of
27.11.2026, rounded down: it asks nothing of old code, but new code without tests shows up as a
drop. Raise the floor when coverage has grown; lower it only with the reason in the commit.
The JavaScript floor is in app/vitest.config.js (coverage.thresholds).
"""
import sys
import xml.etree.ElementTree as ET

# percent; measured 27.11.2026: lines 57.39 (21330/37165), methods 41.09 (1154/2808)
FLOOR = {"lines": 56.5, "methods": 40.5}


def totals(path):
    root = ET.parse(path).getroot()
    metrics = root.find("./project/metrics")
    if metrics is None:
        raise SystemExit(f"check-php-coverage: no project metrics in {path}")
    a = metrics.attrib
    return {
        "lines": (int(a["coveredstatements"]), int(a["statements"])),
        "methods": (int(a["coveredmethods"]), int(a["methods"])),
    }


def main(argv):
    path = argv[1] if len(argv) > 1 else "app/coverage/php/clover.xml"
    failed = False
    for name, (covered, total) in totals(path).items():
        pct = 100.0 * covered / total if total else 0.0
        ok = pct >= FLOOR[name]
        failed |= not ok
        print(f"{'ok  ' if ok else 'FAIL'} {name}: {pct:.2f} % ({covered}/{total}), floor {FLOOR[name]} %")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))

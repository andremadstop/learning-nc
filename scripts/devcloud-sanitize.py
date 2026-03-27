#!/usr/bin/env python3
"""
devcloud-sanitize.py — Sanitize CompTIA learning content for DevCloud publication.

Replaces personal references (hostnames, IPs, usernames, paths) with generic
equivalents and copies sanitized Markdown files to _devcloud/ staging directories.

Usage:
    devcloud-sanitize.py                    # Sanitize + copy (default)
    devcloud-sanitize.py --dry-run          # Show what would change, write nothing
    devcloud-sanitize.py --track            # Sanitize + copy + generate _manifest.json
    devcloud-sanitize.py --track-only       # Only regenerate _manifest.json (no sanitize)
    devcloud-sanitize.py --verbose          # Also show files that need no changes
"""

import argparse
import json
import os
import re
import shutil
import sys
from datetime import datetime, timezone

# ---------------------------------------------------------------------------
# Track configuration
# ---------------------------------------------------------------------------
TRACKS = {
    "Comptia Netzwerk+": "Netzwerk-Plus",
    "Comptia Security+": "Security-Plus",
    "Comptia Linux+": "Linux-Plus",
    "Comptia CySA+": "CySA-Plus",
}

BASE = os.path.expanduser("~/ObsidianVaults/Personal/Wissen/IT")

# ---------------------------------------------------------------------------
# Replacement rules
#
# Each entry: (pattern, replacement, flags)
# flags=0 means case-sensitive, flags=re.IGNORECASE means case-insensitive.
#
# Order matters: specific patterns (hostname+IP combos) before standalone names.
# ---------------------------------------------------------------------------

# -- Hostnames with IP suffixes (case-insensitive for hostnames) --
_HOSTNAME_IP_RULES = [
    (r'cockpit\s*\(?\s*\.?59\)?',       'server-mgmt (10.0.0.1)',     re.IGNORECASE),
    (r'learning-dev\s*\(?\s*\.?65\)?',   'web-server (10.0.0.5)',      re.IGNORECASE),
    (r'proxmox\s*\(?\s*\.?10\)?',        'hypervisor (10.0.0.10)',     re.IGNORECASE),
    (r'n8n\s*\(?\s*\.?68\)?',            'automation-server (10.0.0.8)', re.IGNORECASE),
    (r'nextcloud\s*\(?\s*\.?47\)?',      'cloud-server (10.0.0.7)',    re.IGNORECASE),
    (r'adguard\s*\(?\s*\.?30\)?',        'dns-server (10.0.0.3)',      re.IGNORECASE),
    (r'vaultwarden\s*\(?\s*\.?39\)?',    'password-server (10.0.0.9)', re.IGNORECASE),
    (r'homeassistant\s*\(?\s*\.?36\)?',  'iot-hub (10.0.0.6)',         re.IGNORECASE),
]

# -- Standalone hostnames (case-insensitive) --
_HOSTNAME_STANDALONE_RULES = [
    (r'\blearning-dev\b',          'web-server',       re.IGNORECASE),
    (r'\bcockpit\b(?!\s*UI)',      'server-mgmt',      0),  # case-sensitive: don't match "Cockpit UI"
    (r'\bproxmox\b',              'hypervisor',        re.IGNORECASE),
    (r'\badguard\b',              'dns-server',        re.IGNORECASE),
    (r'\bvaultwarden\b',          'password-server',   re.IGNORECASE),
    (r'\bhomeassistant\b',        'iot-hub',           re.IGNORECASE),
    (r'\blearning-app\b',         'web-container',     re.IGNORECASE),
    (r'\blearning-db\b',          'db-container',      re.IGNORECASE),
]

# -- IP addresses (always case-sensitive, IPs have no case) --
_IP_RULES = [
    (r'192\.168\.178\.59\b',      '10.0.0.1',         0),
    (r'192\.168\.178\.65\b',      '10.0.0.5',         0),
    (r'192\.168\.178\.10\b',      '10.0.0.10',        0),
    (r'192\.168\.178\.68\b',      '10.0.0.8',         0),
    (r'192\.168\.178\.47\b',      '10.0.0.7',         0),
    (r'192\.168\.178\.30\b',      '10.0.0.3',         0),
    (r'192\.168\.178\.39\b',      '10.0.0.9',         0),
    (r'192\.168\.178\.36\b',      '10.0.0.6',         0),
    (r'192\.168\.178\.42\b',      '10.0.0.4',         0),
    (r'192\.168\.178\.0/24',      '10.0.0.0/24',      0),
    (r'192\.168\.178\.\s*\d+',    '10.0.0.x',         0),
    (r'172\.18\.0\.\d+',          '172.18.0.x',       0),
]

# -- SSH/SCP commands (case-sensitive, these are CLI commands) --
_SSH_RULES = [
    (r'ssh\s+learning-dev\b',     'ssh web-server',           0),
    (r'ssh\s+cockpit\b',          'ssh server-mgmt',          0),
    (r'ssh\s+proxmox\b',          'ssh hypervisor',           0),
    (r'ssh\s+n8n\b',              'ssh automation-server',    0),
    (r'scp\s+proxmox:',           'scp hypervisor:',          0),
    (r'scp\s+learning-dev:',      'scp web-server:',          0),
]

# -- Usernames and paths (case-sensitive) --
_USERNAME_RULES = [
    (r'\bandremadstop\b',         'student',                  0),
    (r'/home/andre\b',            '/home/student',            0),
]

# -- External IPs --
_EXTERNAL_IP_RULES = [
    (r'91\.98\.44\.122\b',        '203.0.113.50',             0),
]

# -- Obsidian wiki-links to internal services --
_OBSIDIAN_LINK_RULES = [
    (r'\[\[Ops/services/adguard\|AdGuard\]\]',  'AdGuard Home',  0),
    (r'\[\[Ops/services/n8n\|n8n\]\]',          'n8n',           0),
    (r'\[\[proxmox\|Proxmox\]\]',               'Proxmox',       0),
    (r'\[\[cockpit\|Cockpit\]\]',               'Cockpit',       0),
]

# -- OS-specific references --
_OS_RULES = [
    (r'CachyOS\s*\(\.9\)',                'Linux-Workstation',    0),
    (r'Cockpit/NixOS\s*\(\.59\)',         'Management-Server',    0),
]

# Combined ordered list
REPLACEMENTS = (
    _HOSTNAME_IP_RULES
    + _HOSTNAME_STANDALONE_RULES
    + _IP_RULES
    + _SSH_RULES
    + _USERNAME_RULES
    + _EXTERNAL_IP_RULES
    + _OBSIDIAN_LINK_RULES
    + _OS_RULES
)

# ---------------------------------------------------------------------------
# Skip rules
# ---------------------------------------------------------------------------
SKIP_FILES = {"_meta.md"}
SKIP_DIRS = {"_devcloud", ".obsidian"}


# ---------------------------------------------------------------------------
# Core functions
# ---------------------------------------------------------------------------

def sanitize(text: str) -> tuple[str, int]:
    """Apply all replacement rules. Returns (cleaned_text, replacement_count)."""
    total = 0
    for pattern, replacement, flags in REPLACEMENTS:
        text, n = re.subn(pattern, replacement, text, flags=flags)
        total += n
    return text, total


def count_replacements(text: str) -> int:
    """Count how many replacements would be made without applying them."""
    total = 0
    for pattern, _replacement, flags in REPLACEMENTS:
        total += len(re.findall(pattern, text, flags=flags))
    return total


def build_manifest(dst_dir: str, track_name: str,
                   file_meta: list[dict] | None = None) -> dict:
    """Build manifest dict.

    If file_meta is provided (from a sanitize run), use it directly.
    Otherwise scan dst_dir for existing files (--track-only mode).
    """
    manifest = {
        "generated": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "track": track_name,
        "files": [],
    }

    if file_meta is not None:
        manifest["files"] = sorted(file_meta, key=lambda f: f["path"])
        return manifest

    # Fallback: scan existing _devcloud/ directory
    if not os.path.isdir(dst_dir):
        return manifest

    for root, _dirs, files in os.walk(dst_dir):
        for fname in sorted(files):
            if not fname.endswith(".md"):
                continue
            fpath = os.path.join(root, fname)
            rel = os.path.relpath(fpath, dst_dir)
            stat = os.stat(fpath)
            manifest["files"].append({
                "path": rel,
                "sanitized_mtime": datetime.fromtimestamp(
                    stat.st_mtime, tz=timezone.utc
                ).strftime("%Y-%m-%dT%H:%M:%SZ"),
                "size_bytes": stat.st_size,
            })

    return manifest


def write_manifest(dst_dir: str, track_name: str,
                   file_meta: list[dict] | None = None) -> str:
    """Write _manifest.json to dst_dir. Returns path written."""
    manifest = build_manifest(dst_dir, track_name, file_meta)
    path = os.path.join(dst_dir, "_manifest.json")
    os.makedirs(dst_dir, exist_ok=True)
    with open(path, "w") as f:
        json.dump(manifest, f, indent=2, ensure_ascii=False)
        f.write("\n")
    return path


def process_tracks(*, dry_run: bool = False, track: bool = False,
                   track_only: bool = False, verbose: bool = False) -> int:
    """Main processing loop. Returns 0 on success, 1 on error."""
    stats = {"files": 0, "modified": 0, "clean": 0, "skipped": 0, "errors": 0}

    for track_dir, devcloud_name in TRACKS.items():
        src_dir = os.path.join(BASE, track_dir)
        dst_dir = os.path.join(src_dir, "_devcloud")

        if not os.path.isdir(src_dir):
            print(f"SKIP: {src_dir} not found")
            continue

        # --track-only: just regenerate manifest
        if track_only:
            if os.path.isdir(dst_dir):
                mpath = write_manifest(dst_dir, devcloud_name)
                print(f"  MANIFEST: {mpath}")
            else:
                print(f"  SKIP: {dst_dir} does not exist (run without --track-only first)")
            continue

        print(f"--- {track_dir} ---")
        track_file_meta: list[dict] = []

        for root, dirs, files in os.walk(src_dir):
            # Skip excluded directories
            if any(skip in root.split(os.sep) for skip in SKIP_DIRS):
                continue
            dirs[:] = [d for d in dirs if d not in SKIP_DIRS]

            for fname in sorted(files):
                if not fname.endswith(".md"):
                    continue
                if fname in SKIP_FILES:
                    stats["skipped"] += 1
                    continue

                src_path = os.path.join(root, fname)
                rel_path = os.path.relpath(src_path, src_dir)
                dst_path = os.path.join(dst_dir, rel_path)

                try:
                    with open(src_path, "r", encoding="utf-8") as f:
                        original = f.read()
                except Exception as e:
                    print(f"  ERROR: {rel_path}: {e}")
                    stats["errors"] += 1
                    continue

                src_stat = os.stat(src_path)
                stats["files"] += 1

                if dry_run:
                    n = count_replacements(original)
                    if n > 0:
                        stats["modified"] += 1
                        print(f"  WOULD MODIFY: {rel_path} ({n} replacements)")
                    else:
                        stats["clean"] += 1
                        if verbose:
                            print(f"  OK: {rel_path}")
                else:
                    cleaned, n = sanitize(original)
                    if cleaned != original:
                        stats["modified"] += 1
                        print(f"  CLEAN: {rel_path} ({n} replacements)")
                    else:
                        stats["clean"] += 1
                        if verbose:
                            print(f"  OK: {rel_path}")

                    os.makedirs(os.path.dirname(dst_path), exist_ok=True)
                    with open(dst_path, "w", encoding="utf-8") as f:
                        f.write(cleaned)

                    # Collect metadata for manifest
                    dst_stat = os.stat(dst_path)
                    track_file_meta.append({
                        "path": rel_path,
                        "source_mtime": datetime.fromtimestamp(
                            src_stat.st_mtime, tz=timezone.utc
                        ).strftime("%Y-%m-%dT%H:%M:%SZ"),
                        "sanitized_mtime": datetime.fromtimestamp(
                            dst_stat.st_mtime, tz=timezone.utc
                        ).strftime("%Y-%m-%dT%H:%M:%SZ"),
                        "replacements": n,
                        "size_bytes": dst_stat.st_size,
                    })

        # Generate manifest if --track
        if track and not dry_run:
            mpath = write_manifest(dst_dir, devcloud_name, track_file_meta)
            print(f"  MANIFEST: {mpath}")

        print()

    # Summary
    if track_only:
        print(f"devcloud-sanitize: manifests regenerated for {len(TRACKS)} tracks")
    elif dry_run:
        print(
            f"[DRY RUN] Would modify {stats['modified']} of {stats['files']} files"
            f" ({stats['clean']} clean, {stats['skipped']} skipped)"
        )
    else:
        print(
            f"devcloud-sanitize: {len(TRACKS)} tracks, {stats['files']} files processed, "
            f"{stats['modified']} modified, {stats['clean']} clean, {stats['skipped']} skipped"
        )

    return 1 if stats["errors"] > 0 else 0


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def main():
    parser = argparse.ArgumentParser(
        description="Sanitize CompTIA learning content for DevCloud publication."
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Show what would change without writing any files.",
    )
    parser.add_argument(
        "--track",
        action="store_true",
        help="After sanitizing, generate/update _manifest.json per track.",
    )
    parser.add_argument(
        "--track-only",
        action="store_true",
        help="Only regenerate _manifest.json (no sanitize). Requires existing _devcloud/ dirs.",
    )
    parser.add_argument(
        "--verbose",
        action="store_true",
        help="Also show files that need no changes (default: only modified).",
    )

    args = parser.parse_args()

    # --track-only implies no sanitize, no --dry-run
    if args.track_only and args.dry_run:
        parser.error("--track-only and --dry-run are mutually exclusive")

    rc = process_tracks(
        dry_run=args.dry_run,
        track=args.track,
        track_only=args.track_only,
        verbose=args.verbose,
    )

    # --dry-run always exits 0
    sys.exit(0 if args.dry_run else rc)


if __name__ == "__main__":
    main()

#!/usr/bin/env bash
set -euo pipefail

# DevCloud Staleness Check
# Compares Personal-Vault .md files against their _devcloud/ copies.
# Exit 0 = all up-to-date, 1 = stale/missing found, 2 = error

TRACKS=(
  "Comptia Netzwerk+"
  "Comptia Security+"
  "Comptia Linux+"
  "Comptia CySA+"
)
BASE="$HOME/ObsidianVaults/Personal/Wissen/IT"

# Color output only if stdout is a terminal
if [ -t 1 ]; then
  YELLOW='\033[1;33m'
  RED='\033[1;31m'
  GREEN='\033[0;32m'
  RESET='\033[0m'
else
  YELLOW=''
  RED=''
  GREEN=''
  RESET=''
fi

stale_count=0
missing_count=0
uptodate_count=0
error_count=0

for track in "${TRACKS[@]}"; do
  track_dir="$BASE/$track"
  devcloud_dir="$track_dir/_devcloud"

  if [ ! -d "$track_dir" ]; then
    echo -e "${RED}ERROR: Track directory not found: $track_dir${RESET}" >&2
    error_count=$((error_count + 1))
    continue
  fi

  if [ ! -d "$devcloud_dir" ]; then
    echo -e "${RED}ERROR: _devcloud/ not found: $devcloud_dir${RESET}" >&2
    error_count=$((error_count + 1))
    continue
  fi

  # Find all .md files in the track dir (not in _devcloud/ or .obsidian/)
  while IFS= read -r -d '' vault_file; do
    # Get relative path from track dir
    rel_path="${vault_file#"$track_dir"/}"

    # Skip _meta.md files
    basename_file="$(basename "$rel_path")"
    if [ "$basename_file" = "_meta.md" ]; then
      continue
    fi

    devcloud_file="$devcloud_dir/$rel_path"

    if [ ! -f "$devcloud_file" ]; then
      echo -e "${RED}MISSING${RESET}: $track/$rel_path (exists in Vault, not in _devcloud/)"
      missing_count=$((missing_count + 1))
    elif [ "$vault_file" -nt "$devcloud_file" ]; then
      vault_mtime=$(date -r "$vault_file" "+%Y-%m-%d %H:%M")
      dc_mtime=$(date -r "$devcloud_file" "+%Y-%m-%d %H:%M")
      echo -e "${YELLOW}STALE${RESET}: $track/$rel_path (Vault: $vault_mtime, DevCloud: $dc_mtime)"
      stale_count=$((stale_count + 1))
    else
      uptodate_count=$((uptodate_count + 1))
    fi
  done < <(find "$track_dir" -maxdepth 1 -name "*.md" \
    ! -path "*/_devcloud/*" \
    ! -path "*/.obsidian/*" \
    -print0 2>/dev/null)
done

# Summary
echo ""
if [ $stale_count -eq 0 ] && [ $missing_count -eq 0 ] && [ $error_count -eq 0 ]; then
  echo -e "${GREEN}All $uptodate_count files up-to-date.${RESET}"
  exit 0
elif [ $error_count -gt 0 ] && [ $stale_count -eq 0 ] && [ $missing_count -eq 0 ]; then
  echo "$error_count error(s), $uptodate_count up-to-date"
  exit 2
else
  echo "$stale_count stale, $missing_count missing, $uptodate_count up-to-date"
  exit 1
fi

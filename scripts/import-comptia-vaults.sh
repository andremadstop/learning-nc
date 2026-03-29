#!/usr/bin/env bash
# import-comptia-vaults.sh — Runbook: copy CompTIA Obsidian vaults to Docker and import as RAG chunks
# Usage: ./scripts/import-comptia-vaults.sh [--dry-run] [--vault=<name>] [--skip-copy]
set -euo pipefail

# ── Configuration ────────────────────────────────────────────────────
REMOTE="learning-dev"
CONTAINER="learning-app"
DB_CONTAINER="learning-db"
DB_USER="nextcloud"
DB_NAME="nextcloud"
VAULT_BASE="/data/comptia-vault"
# Bind mount source on learning-dev: ~/comptia-vault -> /data/comptia-vault (ro)
BIND_MOUNT_DIR="\$HOME/comptia-vault"

# Course mapping: slug -> course_id
declare -A COURSE_IDS=(
  [comptia-network-plus]=20
  [comptia-security-plus]=21
  [comptia-cysa-plus]=22
  [comptia-linux-plus]=23
)

# Source vault paths (local workstation)
declare -A VAULT_SOURCES=(
  [comptia-network-plus]="$HOME/ObsidianVaults/Personal/Wissen/IT/Comptia Netzwerk+"
  [comptia-security-plus]="$HOME/ObsidianVaults/Personal/Wissen/IT/Comptia Security+"
  [comptia-cysa-plus]="$HOME/ObsidianVaults/Personal/Wissen/IT/Comptia CySA+"
  [comptia-linux-plus]="$HOME/ObsidianVaults/Personal/Wissen/IT/Comptia Linux+"
)

# ── Parse arguments ──────────────────────────────────────────────────
DRY_RUN=""
SINGLE_VAULT=""
SKIP_COPY=false

for arg in "$@"; do
  case "$arg" in
    --dry-run)    DRY_RUN="--dry-run" ;;
    --vault=*)    SINGLE_VAULT="${arg#--vault=}" ;;
    --skip-copy)  SKIP_COPY=true ;;
    --help|-h)
      echo "Usage: $0 [--dry-run] [--vault=<slug>] [--skip-copy]"
      echo ""
      echo "Vaults: comptia-network-plus, comptia-security-plus, comptia-cysa-plus, comptia-linux-plus"
      echo ""
      echo "  --dry-run    Pass --dry-run to occ (preview without DB writes)"
      echo "  --vault=X    Import only one vault"
      echo "  --skip-copy  Skip rsync (vaults already on learning-dev bind mount)"
      exit 0
      ;;
    *) echo "Unknown arg: $arg"; exit 1 ;;
  esac
done

# ── Determine which vaults to process ────────────────────────────────
if [[ -n "$SINGLE_VAULT" ]]; then
  if [[ -z "${COURSE_IDS[$SINGLE_VAULT]+x}" ]]; then
    echo "ERROR: Unknown vault '$SINGLE_VAULT'"
    echo "Valid: ${!COURSE_IDS[*]}"
    exit 1
  fi
  VAULTS=("$SINGLE_VAULT")
else
  VAULTS=(comptia-network-plus comptia-security-plus comptia-cysa-plus comptia-linux-plus)
fi

# ── Process each vault ───────────────────────────────────────────────
TOTAL_VAULTS=0

for slug in "${VAULTS[@]}"; do
  course_id="${COURSE_IDS[$slug]}"
  source="${VAULT_SOURCES[$slug]}"

  echo ""
  echo "━━━ $slug (course $course_id) ━━━"

  if [[ "$SKIP_COPY" == false ]]; then
    # Step 1: rsync directly to bind mount source on learning-dev
    # ~/comptia-vault is bind-mounted as /data/comptia-vault (ro) in the container
    echo "  [1/2] rsync to $REMOTE:~/comptia-vault/$slug/"
    ssh "$REMOTE" "mkdir -p ~/comptia-vault/$slug"
    rsync -avz --delete \
      --exclude='.obsidian' --exclude='Eigene-Notizen' --exclude='Bilder' \
      --exclude='.venv' --exclude='tools' \
      "$source/" "$REMOTE:~/comptia-vault/$slug/"
  else
    echo "  [skip] Copy skipped (--skip-copy)"
  fi

  # Step 3: Run import
  echo "  [2/2] occ learning:import-vault $DRY_RUN"
  ssh "$REMOTE" "docker exec -u www-data $CONTAINER php occ learning:import-vault $VAULT_BASE/$slug --course-id=$course_id $DRY_RUN"

  TOTAL_VAULTS=$((TOTAL_VAULTS + 1))
done

# ── Summary ──────────────────────────────────────────────────────────
echo ""
echo "━━━ Summary ━━━"
echo "Vaults processed: $TOTAL_VAULTS"
if [[ -n "$DRY_RUN" ]]; then
  echo "Mode: DRY RUN (no DB writes)"
else
  echo "Mode: LIVE IMPORT"
  echo ""
  echo "Chunk counts per course:"
  ssh "$REMOTE" "docker exec $DB_CONTAINER psql -U $DB_USER -d $DB_NAME -c \"SELECT course_id, COUNT(*) as chunks FROM oc_learning_rag_chunks WHERE source_type='vault' AND course_id IN (20,21,22,23) GROUP BY course_id ORDER BY course_id;\""
fi

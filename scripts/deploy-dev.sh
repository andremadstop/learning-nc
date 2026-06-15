#!/bin/bash
# Compatibility wrapper: the old learning-dev CT is obsolete.
# DevCloud now runs on relais as devcloud-app/devcloud-db.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "WARN: learning-dev CT is obsolete; forwarding to deploy-prod.sh on relais/devcloud-app." >&2
exec "$SCRIPT_DIR/deploy-prod.sh" "$@"

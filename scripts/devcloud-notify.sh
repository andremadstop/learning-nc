#!/usr/bin/env bash
# Post a notification to the DevCloud Talk "Allgemein" channel
# when learning content is updated.
#
# Usage: ./scripts/devcloud-notify.sh "Neue Nmap-Anleitung verfuegbar"
# Called automatically by /lerninhalt skill after content deployment.

set -euo pipefail

TALK_TOKEN="amc9qut5"
NC_URL="${DEVCLOUD_URL:-https://devcloud.andrestiebitz.de}"
NC_USER="${DEVCLOUD_USER:-andre}"
NC_PASS="${DEVCLOUD_PASS:-}"

if [ -z "$NC_PASS" ]; then
    echo "ERROR: DEVCLOUD_PASS nicht gesetzt. Export DEVCLOUD_PASS=... oder .env laden." >&2
    exit 1
fi

MESSAGE="${1:-Neue Lerninhalte auf der DevCloud verfuegbar!}"

RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" \
    -u "${NC_USER}:${NC_PASS}" \
    -H "OCS-APIREQUEST: true" \
    -H "Content-Type: application/json" \
    -X POST "${NC_URL}/ocs/v2.php/apps/spreed/api/v1/chat/${TALK_TOKEN}" \
    -d "{\"message\":\"${MESSAGE}\"}" 2>/dev/null)

if [ "$RESPONSE" = "201" ]; then
    echo "Talk-Nachricht gepostet in Allgemein."
elif [ "$RESPONSE" = "500" ]; then
    echo "WARN: Talk-API gibt 500 (bekanntes Problem ohne HPB). Nachricht moeglicherweise nicht gepostet." >&2
    exit 0
else
    echo "ERROR: Talk-API HTTP $RESPONSE" >&2
    exit 1
fi

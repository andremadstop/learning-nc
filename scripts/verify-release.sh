#!/bin/bash
# verify-release.sh <version>  — Post-Release-Gate fuer den Nextcloud App Store.
#
# Maschinell statt manuell: faengt die Fehlerklassen, die App-Store-201 + integrity:check
# NICHT bemerken und die in der Vergangenheit zu nicht-installierbaren Releases gefuehrt haben:
#   • Draft-Falle (#23 v4.4.6, #26 v4.4.7): Release blieb Draft → kanonische Tag-URL 404,
#     obwohl der App-Store-Eintrag (201) darauf zeigt → `occ app:install` schlaegt fuer ALLE User fehl.
#   • Signatur-Mismatch: falscher/zweiter Build publiziert → NC verwirft die Signatur beim Install.
#   • Packaging-Bug (v4.4.5): `data/` fehlt im Tarball → App laedt, Features ohne Daten.
#   • Versions-Drift: info.xml != Release-Tag.
#
# HOST-AGNOSTISCH: prueft gegen die download-URL, die der App-Store-Feed TATSAECHLICH ausliefert.
# Damit deckt es sowohl die Alt-Releases auf GitHub als auch neue Releases auf Codeberg ab
# (Release-Hosting-Cutover GitHub→Codeberg, 2026-06-24) — ohne Umschreiben.
#
# Nach dem App-Store-POST (Checkliste Schritt 9) laufen lassen. Exit 0 = installierbar.
#
# Usage: ./scripts/verify-release.sh 4.4.7
set -eo pipefail

VERSION="${1:?Usage: verify-release.sh <version, z.B. 4.4.7>}"
PLATFORM="${NC_PLATFORM:-33.0.0}"   # App-Store-Feed-Version, den NC-Clients abfragen

fail() { echo "✗ $1" >&2; exit 1; }

# Zertifikat finden (certs/ ist gitignored; Fallback auf globalen NC-Cert-Store)
CERT="${LEARNING_CERT:-}"
if [ -z "$CERT" ]; then
  for c in "$(dirname "$0")/../certs/learning.crt" "$HOME/.nextcloud/certificates/learning.crt"; do
    [ -f "$c" ] && CERT="$c" && break
  done
fi

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo "=== verify-release v$VERSION ==="

# 1. App-Store-Feed: Version vorhanden, download-URL + Signatur extrahieren.
#    Die download-URL ist die maßgebliche Quelle — exakt das, was ein NC-Client laden wuerde.
curl -s "https://apps.nextcloud.com/api/v1/platform/${PLATFORM}/apps.json" -o "$TMP/feed.json"
python3 - "$VERSION" "$TMP/feed.json" "$TMP/url.txt" "$TMP/as.b64" <<'PY' || fail "App-Store-Feed-Check fehlgeschlagen"
import json, sys
ver, feed, url_out, sig_out = sys.argv[1:5]
try:
    data = json.load(open(feed))
except Exception as e:
    sys.exit(f"App-Store-Feed nicht parsebar: {e}")
app = [a for a in data if a.get('id') == 'learning']
if not app:
    sys.exit("App 'learning' nicht im App-Store-Feed")
rel = [r for r in app[0].get('releases', []) if r['version'] == ver]
if not rel:
    sys.exit(f"v{ver} nicht im App-Store-Feed (POST vergessen?)")
r = rel[0]
url = r.get('download') or ''
sig = r.get('signature') or ''
if not url.startswith('https://') or not url.endswith('.tar.gz'):
    sys.exit(f"download-URL ungueltig (App Store verlangt https + .tar.gz): {url}")
if not sig:
    sys.exit(f"Keine Signatur fuer v{ver} im App-Store-Feed")
open(url_out, 'w').write(url)
open(sig_out, 'w').write(sig)
PY
URL="$(cat "$TMP/url.txt")"
HOST="$(printf '%s' "$URL" | awk -F/ '{print $3}')"
echo "✓ App-Store-Feed: v$VERSION gelistet, download-Host = $HOST"

# 2. Kanonische download-URL liefert nach Redirect HTTP 200 (Draft-Falle gibt hier 404)
CODE="$(curl -sIL -o /dev/null -w '%{http_code}' "$URL")"
[ "$CODE" = "200" ] || fail "download-URL liefert HTTP $CODE (erwartet 200) — Draft-Falle? $URL"
echo "✓ download-URL: HTTP 200"

# 3. Publizierten Tarball laden
curl -sL -o "$TMP/app.tar.gz" "$URL"
[ -s "$TMP/app.tar.gz" ] || fail "Tarball-Download leer"

# 4. App-Store-Signatur verifiziert byte-genau gegen den PUBLIZIERTEN Tarball
[ -n "$CERT" ] || fail "Kein Zertifikat gefunden (certs/learning.crt oder ~/.nextcloud/certificates/learning.crt)"
openssl base64 -d -A < "$TMP/as.b64" > "$TMP/as.bin"
openssl x509 -in "$CERT" -pubkey -noout > "$TMP/pub.pem" 2>/dev/null \
  || fail "Public Key konnte nicht aus $CERT extrahiert werden"
openssl dgst -sha512 -verify "$TMP/pub.pem" -signature "$TMP/as.bin" "$TMP/app.tar.gz" >/dev/null 2>&1 \
  || fail "App-Store-Signatur passt NICHT zum publizierten Tarball — falscher Build publiziert? (NC verwirft das beim Install)"
echo "✓ App-Store-Signatur == publizierter Tarball (Verified OK)"

# 5. Host-spezifischer Sanity-Check: genau EIN non-draft Release fuer den Tag (Draft-Leichen aufdecken).
#    Best-effort — der 200-Check (Schritt 2) faengt die Draft-Falle bereits; dies deckt zusaetzlich
#    verwaiste Zweit-Drafts auf (vgl. #26: zwei untagged-Drafts). Bricht nicht, wenn API nicht erreichbar.
OWNER_REPO="$(printf '%s' "$URL" | sed -E 's#https://[^/]+/([^/]+/[^/]+)/releases/.*#\1#')"
TAG="v${VERSION}"
case "$HOST" in
  github.com)
    if command -v gh >/dev/null 2>&1; then
      mapfile -t D < <(gh api "repos/$OWNER_REPO/releases" --jq ".[] | select(.tag_name==\"$TAG\") | .draft" 2>/dev/null || true)
      if [ "${#D[@]}" -gt 0 ]; then
        { [ "${#D[@]}" -eq 1 ] && [ "${D[0]}" = "false" ]; } \
          || fail "GitHub: ${#D[@]} Release(s) fuer $TAG, draft=${D[*]} — erwartet genau 1 non-draft (verwaiste Drafts loeschen)"
        echo "✓ GitHub-Release $TAG: non-draft, genau 1 Eintrag"
      fi
    fi
    ;;
  codeberg.org)
    API="https://codeberg.org/api/v1/repos/$OWNER_REPO/releases"
    AUTH=(); [ -f "$HOME/.config/codeberg/token" ] && AUTH=(-H "Authorization: token $(cat "$HOME/.config/codeberg/token")")
    if RESP="$(curl -s "${AUTH[@]}" "$API" 2>/dev/null)"; then
      DRAFTS="$(printf '%s' "$RESP" | python3 -c "import json,sys; rels=[r for r in json.load(sys.stdin) if r.get('tag_name')=='$TAG']; print(' '.join(str(r.get('draft')) for r in rels))" 2>/dev/null || echo "")"
      if [ -n "$DRAFTS" ]; then
        [ "$DRAFTS" = "False" ] || fail "Codeberg: Release(s) fuer $TAG draft=$DRAFTS — erwartet genau 1 non-draft"
        echo "✓ Codeberg-Release $TAG: non-draft, genau 1 Eintrag"
      fi
    fi
    ;;
esac

# 6. Packaging-Gates: data/ vorhanden + info.xml-Version == Tag
tar -xzf "$TMP/app.tar.gz" -C "$TMP"
DATA_FILES="$(find "$TMP/learning/data" -type f 2>/dev/null | wc -l)"
[ "$DATA_FILES" -gt 0 ] || fail "Tarball enthaelt KEIN data/ (Packaging-Bug wie v4.4.5)"
INFO_VER="$(grep -oE '<version>[0-9.]+</version>' "$TMP/learning/appinfo/info.xml" | head -1 | grep -oE '[0-9.]+')"
[ "$INFO_VER" = "$VERSION" ] || fail "info.xml-Version ($INFO_VER) != $VERSION"
echo "✓ Packaging: data/ vorhanden ($DATA_FILES files), info.xml == $VERSION"

echo ""
echo "✅ Release v$VERSION ist installierbar (Host: $HOST) — alle Gates gruen."

#!/bin/bash
# verify-release.sh <version>  — Post-Release-Gate fuer den Nextcloud App Store.
#
# Maschinell statt manuell: faengt die Fehlerklassen, die App-Store-201 + integrity:check
# NICHT bemerken und die in der Vergangenheit zu nicht-installierbaren Releases gefuehrt haben:
#   • Draft-Falle (#23 v4.4.6, #26 v4.4.7): GitHub-Release blieb Draft → kanonische Tag-URL 404,
#     obwohl der App-Store-Eintrag (201) darauf zeigt → `occ app:install` schlaegt fuer ALLE User fehl.
#   • Signatur-Mismatch: falscher/zweiter Build publiziert → NC verwirft die Signatur beim Install.
#   • Packaging-Bug (v4.4.5): `data/` fehlt im Tarball → App laedt, Features ohne Daten.
#   • Versions-Drift: info.xml != Release-Tag.
#
# Nach dem App-Store-POST (Checkliste Schritt 9) laufen lassen. Exit 0 = installierbar.
#
# Usage: ./scripts/verify-release.sh 4.4.7
set -eo pipefail

VERSION="${1:?Usage: verify-release.sh <version, z.B. 4.4.7>}"
REPO="${LEARNING_REPO:-andremadstop/learning-nc}"
TAG="v${VERSION}"
URL="https://github.com/${REPO}/releases/download/${TAG}/learning-${VERSION}.tar.gz"
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

echo "=== verify-release v$VERSION ($REPO) ==="

# 1. Genau EIN non-draft Release fuer den Tag (Draft-Leichen aufdecken)
mapfile -t DRAFTS < <(gh api "repos/$REPO/releases" --jq ".[] | select(.tag_name==\"$TAG\") | .draft")
[ "${#DRAFTS[@]}" -ge 1 ] || fail "Kein GitHub-Release mit Tag $TAG gefunden"
[ "${#DRAFTS[@]}" -eq 1 ] || fail "${#DRAFTS[@]} Releases mit Tag $TAG (erwartet 1) — verwaiste Draft(s) loeschen"
[ "${DRAFTS[0]}" = "false" ] || fail "GitHub-Release $TAG ist noch DRAFT → publizieren: gh release edit $TAG --draft=false --latest"
echo "✓ GitHub-Release $TAG: non-draft, genau 1 Eintrag"

# 2. Kanonische Tag-URL liefert nach Redirect HTTP 200 (Draft-Falle gibt hier 404)
CODE="$(curl -sIL -o /dev/null -w '%{http_code}' "$URL")"
[ "$CODE" = "200" ] || fail "Kanonische Download-URL liefert HTTP $CODE (erwartet 200) — Draft-Falle? $URL"
echo "✓ Kanonische URL: HTTP 200"

# 3. Publizierten Tarball laden
curl -sL -o "$TMP/app.tar.gz" "$URL"
[ -s "$TMP/app.tar.gz" ] || fail "Tarball-Download leer"

# 4. App-Store-Feed: Version vorhanden, download == kanonische URL, Signatur extrahieren
curl -s "https://apps.nextcloud.com/api/v1/platform/${PLATFORM}/apps.json" -o "$TMP/feed.json"
python3 - "$VERSION" "$TMP/feed.json" "$TMP/as.b64" "$URL" <<'PY' || fail "App-Store-Feed-Check fehlgeschlagen"
import json, sys
ver, feed, out, url = sys.argv[1:5]
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
if r.get('download') != url:
    sys.exit(f"App-Store download-URL != kanonische URL:\n  store: {r.get('download')}\n  canon: {url}")
sig = r.get('signature') or ''
if not sig:
    sys.exit(f"Keine Signatur fuer v{ver} im App-Store-Feed")
open(out, 'w').write(sig)
PY
echo "✓ App-Store-Feed: v$VERSION gelistet, download-URL == kanonische URL"

# 5. App-Store-Signatur verifiziert gegen den PUBLIZIERTEN Tarball (byte-genau)
[ -n "$CERT" ] || fail "Kein Zertifikat gefunden (certs/learning.crt oder ~/.nextcloud/certificates/learning.crt)"
openssl base64 -d -A < "$TMP/as.b64" > "$TMP/as.bin"
openssl x509 -in "$CERT" -pubkey -noout > "$TMP/pub.pem" 2>/dev/null \
  || fail "Public Key konnte nicht aus $CERT extrahiert werden"
openssl dgst -sha512 -verify "$TMP/pub.pem" -signature "$TMP/as.bin" "$TMP/app.tar.gz" >/dev/null 2>&1 \
  || fail "App-Store-Signatur passt NICHT zum publizierten Tarball — falscher Build publiziert? (NC verwirft das beim Install)"
echo "✓ App-Store-Signatur == publizierter Tarball (Verified OK)"

# 6. Packaging-Gates: data/ vorhanden + info.xml-Version == Tag
tar -xzf "$TMP/app.tar.gz" -C "$TMP"
DATA_FILES="$(find "$TMP/learning/data" -type f 2>/dev/null | wc -l)"
[ "$DATA_FILES" -gt 0 ] || fail "Tarball enthaelt KEIN data/ (Packaging-Bug wie v4.4.5)"
INFO_VER="$(grep -oE '<version>[0-9.]+</version>' "$TMP/learning/appinfo/info.xml" | head -1 | grep -oE '[0-9.]+')"
[ "$INFO_VER" = "$VERSION" ] || fail "info.xml-Version ($INFO_VER) != $VERSION"
echo "✓ Packaging: data/ vorhanden ($DATA_FILES files), info.xml == $VERSION"

echo ""
echo "✅ Release v$VERSION ist installierbar — alle Gates gruen."

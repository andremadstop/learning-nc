#!/bin/bash
# GitHub #94: the certificate PDF gate (app/tests/print/certificate-pdf.spec.cjs), on the desktop and
# on a phone in Chromium and WebKit (Safari's engine). No Nextcloud needed.
#
# Chromium is the system browser, like the print gate. Playwright's WebKit build links against
# Ubuntu's libraries (libicu74, libflite1), which other hosts do not have, so WebKit runs in
# Playwright's container: it only serves the browser (playwright run-server, host network), the
# tests and their checks (pdfinfo, pdfimages, qpdf, zbarimg) stay on this machine. The container
# uses this checkout's playwright-core and the WebKit revision that version asks for, read only.
#
# Usage: scripts/pdf-gate.sh [playwright test arguments]
# Needs: docker, the image below, `npx playwright install webkit` once, poppler-utils, qpdf, zbar.
set -euo pipefail

APP="$(cd "$(dirname "$0")/../app" && pwd)"
# Playwright's own image (Ubuntu 24.04), pinned by digest; only its system libraries are used.
IMAGE="${PDF_GATE_IMAGE:-mcr.microsoft.com/playwright@sha256:eff16c30e6f3f4af0a03fa4b706120d5e9b0891c344a27d64559aff5900a4a27}"

for tool in docker pdfinfo pdfimages pdftoppm pdftotext qpdf zbarimg; do
	command -v "$tool" >/dev/null || { echo "pdf-gate: $tool is not installed" >&2; exit 1; }
done

MODULES="$(realpath "$APP/node_modules")"
REVISION="$(node -e "const b=require('$MODULES/playwright-core/browsers.json').browsers.find(x=>x.name==='webkit');process.stdout.write(b.revision)")"
BROWSERS="${PLAYWRIGHT_BROWSERS_PATH:-$HOME/.cache/ms-playwright}"
WEBKIT="$BROWSERS/webkit-$REVISION"
if [ ! -x "$WEBKIT/pw_run.sh" ]; then
	echo "pdf-gate: WebKit $REVISION missing in $BROWSERS — run: (cd app && npx playwright install webkit)" >&2
	exit 1
fi

PORT="$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1", 0)); print(s.getsockname()[1]); s.close()')"
NAME="learning-pdf-webkit-$$"
cleanup() { docker rm -f "$NAME" >/dev/null 2>&1 || true; }
trap cleanup EXIT

docker run -d --rm --name "$NAME" --network host --init \
	-v "$MODULES:/pw/node_modules:ro" \
	-v "$WEBKIT:/ms-playwright/webkit-$REVISION:ro" \
	-e PLAYWRIGHT_BROWSERS_PATH=/ms-playwright \
	"$IMAGE" node /pw/node_modules/playwright-core/cli.js run-server --host 127.0.0.1 --port "$PORT" >/dev/null

for _ in $(seq 1 60); do
	if docker logs "$NAME" 2>&1 | grep -q 'Listening on'; then
		break
	fi
	if ! docker inspect "$NAME" >/dev/null 2>&1; then
		echo "pdf-gate: the WebKit container stopped" >&2
		exit 1
	fi
	sleep 0.5
done
docker logs "$NAME" 2>&1 | grep -q 'Listening on' || { echo "pdf-gate: the WebKit server did not start" >&2; docker logs "$NAME" >&2; exit 1; }

cd "$APP"
PDF_WEBKIT_WS="ws://127.0.0.1:$PORT/" npx playwright test -c tests/print/playwright.config.cjs \
	--project pdf-desktop --project chromium-phone --project webkit-phone "$@"

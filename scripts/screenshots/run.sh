#!/usr/bin/env bash
# screenshots/run.sh — the README, guide and store screenshots, reproducible (GitHub #74).
#
# Usage: scripts/screenshots/run.sh [all | up | shoot | down]
#          all    (default) up → shoot → down
#          up     a throwaway Nextcloud with the app from REF, seeded like the persona gate
#                 (mandatory-training course, required materials, certificate name and logo), plus
#                 the demo data of this script (below); stays running
#          shoot  take the pictures against the running instance, optimise them and check the budget
#          down   remove the instance
#        Environment: REF=<git-ref>  app snapshot (default: HEAD); the committed bundle in app/js is
#                                    what gets photographed
#                     KEEP=1         with `all`: leave the instance running afterwards
#
# Built on scripts/persona-sweep: its `up` (same images, same seed) runs with PERSONA_OUT set to
# this folder's out/, so a persona run and a screenshot run never share an instance. On top:
#   - fictional display names for the learners and the instructor (the persona sweep checks for
#     "Learner 1" and keeps its own names);
#   - one question of examples/app-tour ("How to learn with this app", app-tour-11-en) with its hint,
#     feedback per option, intro and dialog, as an optional pool of the course; the example's own
#     content, no third-party exam questions;
#   - the VirtuProf side panel switched off for everyone (a personal setting), so the screens show
#     the app at full width;
#   - a wordmark logo for the fictional organisation of the seed (rendered in shots.spec.cjs).
# The picture list, the viewport and the size budget are in lib.mjs.
#
# Output: scripts/screenshots/out/ (gitignored): instance.env (chmod 600), seed.json, raw/*.png.
#         The optimised pictures go straight to screenshots/ and docs/guides/img/ (lib.mjs: SHOTS).
#         Needs Docker, /usr/bin/chromium (or SHOTS_CHROMIUM) and ImageMagick (`magick`).
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$HERE/../.." && pwd)"
OUT="$HERE/out"
STATE="$OUT/instance.env"
REF="${REF:-HEAD}"
CMD="${1:-all}"
export PERSONA_OUT="$OUT"

mkdir -p "$OUT"
chmod 700 "$OUT"

nc_container() { sed -n "s/^NC='\(.*\)'$/\1/p" "$STATE"; }
occ() { docker exec -u www-data -w /var/www/html "$(nc_container)" php occ "$@"; }

up() {
	command -v magick >/dev/null || { echo "screenshots: ImageMagick (magick) is missing" >&2; exit 2; }
	GAMIFICATION=off REF="$REF" "$REPO_ROOT/scripts/persona-sweep/run.sh" up

	echo "==> fictional names"
	occ user:setting instructor settings display_name "Dana Wexmoor" >/dev/null
	occ user:setting learner1 settings display_name "Tamsin Rowe-Halden" >/dev/null
	occ user:setting learner2 settings display_name "Joel Vantreck" >/dev/null
	occ user:setting learner3 settings display_name "Priya Castellane" >/dev/null
	# The VirtuProf side panel opens with a tip on every new screen and takes a third of the window;
	# it is optional (personal settings → Virtual assistant), so the pictures show the app without it.
	for u in admin instructor learner1 learner2 learner3; do
		occ user:setting "$u" learning virtuprof_enabled no >/dev/null
	done

	echo "==> one question of examples/app-tour with its media"
	local tour bundle nc
	tour="$(mktemp -d "${TMPDIR:-/tmp}/learning-shots-tour.XXXXXX")"
	git -C "$REPO_ROOT" archive "$REF" examples/app-tour | tar -x -C "$tour"
	bundle="$tour/bundle"
	# Imports the question as the instructor, adds its pool to the course and writes the bundle of
	# that one question to $bundle; prints the pool id.
	local pool_id
	pool_id="$(PERSONA_STATE="$STATE" node "$HERE/seed.mjs" "$tour/examples/app-tour" "$bundle")"
	nc="$(nc_container)"
	docker cp "$bundle/." "$nc:/tmp/shots-app-tour"
	rm -r -- "$tour"
	docker exec "$nc" chown -R www-data:www-data /tmp/shots-app-tour
	occ learning:import-question-media "$pool_id" /tmp/shots-app-tour
	echo "screenshots: up (state in $STATE)"
}

shoot() {
	[ -f "$STATE" ] || { echo "screenshots: no instance — run '$0 up' first" >&2; exit 2; }
	rm -rf -- "$OUT/raw" "$OUT/pw-results"
	mkdir -p "$OUT/raw"
	(cd "$REPO_ROOT/app" && PERSONA_STATE="$STATE" NODE_PATH="$REPO_ROOT/app/node_modules" \
		npx playwright test -c "$HERE/shots.config.cjs")
	node "$HERE/optimise.mjs" "$OUT/raw" "$REPO_ROOT"
}

down() { "$REPO_ROOT/scripts/persona-sweep/run.sh" down; }

case "$CMD" in
	up) up ;;
	shoot) shoot ;;
	down) down ;;
	all)
		[ "${KEEP:-}" = 1 ] || trap 'down || true' EXIT
		up
		shoot
		;;
	*) echo "usage: $0 [all | up | shoot | down]" >&2; exit 2 ;;
esac

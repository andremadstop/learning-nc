#!/usr/bin/env bash
# persona-sweep/run.sh — click through Learning as an outsider would (Persona-Gate).
#
# Usage: scripts/persona-sweep/run.sh [all | up | sweep | down]
#          all    (default) up → sweep → report → down
#          up     start a throwaway Nextcloud with the app from HEAD and seed it; stays running
#          sweep  run the Playwright persona sweep against the running instance, write the report
#          down   remove the instance
#        Environment: GAMIFICATION=on   `up`/`all`: seed an IT course as before #36 — gamification on,
#                                       every course mode on (Leitner included), AI on with an Ollama
#                                       connection nothing answers (no external provider). Default
#                                       off: the mandatory-training scenario (gamification, AI and
#                                       Leitner off). `sweep` takes the mode of the running instance.
#                     REF=<git-ref>     app snapshot to test (default: HEAD)
#                     KEEP=1            with `all`: leave the instance running afterwards
#                     PERSONA_STATIONS=<regex>  `sweep`: only these stations (findings-filtered.json,
#                                       report-filtered.md, stations-filtered.json, shots-filtered/)
#                     PERSONA_DEBUG=1   `sweep`: log every button the click-through finds
#                     PERSONA_OUT=<dir> `up`/`down`: state and seed of the instance there instead of out/ (used by
#                                       scripts/screenshots, so that its instance and a persona run
#                                       never share a state file)
#        Both modes against each other: GAMIFICATION=off …/run.sh, then GAMIFICATION=on …/run.sh;
#        the results land in separate folders.
#
# Why: our gates read code; none of them clicks from the outside. 24 external issues in a row
# (GitHub #35–#61) were found by one operator who clicked through the app with gamification, AI
# and Leitner off. This sweep plays his scenario on a fresh instance: an administrator, an
# instructor, three learners, a mandatory-training course with two required pools, certification
# at 75 %, English UI. Every station is screenshotted and checked for failed app requests, console
# errors, empty main areas, success toasts without a successful request, German words, and
# accessibility with axe-core (app/node_modules, pinned devDependency): every contrast violation and
# every critical or serious WCAG 2.1 A/AA violation in #app-content. The toast and the contrast check
# each prove themselves at the start (self-test stations). Findings that are the app working as
# intended (lib.cjs: expectedReason) are listed separately and not counted.
#
# Output: scripts/persona-sweep/out/ (gitignored)
#           instance.env   chmod 600; URL, container names and the throwaway passwords
#           seed.json      course/pool ids, pool owner, mode config, AI
#           gamification-<off|on>/
#             report.md      findings, deduplicated, plus every station with its screenshot
#             findings.json  raw findings per station
#             stations.json  per station: persona, URL, screenshot, visible text (≤ 1500 chars) —
#                            the input for the fresh-eyes review without code (FRESH-EYES.md)
#             shots/*.png, downloads/
#
# Nextcloud 33 + PostgreSQL 16, pinned by digest (same images as nc-integration.sh). The app comes
# from `git archive $REF`, so the committed bundle in app/js is what gets clicked — what ships.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$HERE/../.." && pwd)"
OUT="${PERSONA_OUT:-$HERE/out}"
STATE="$OUT/instance.env"
NC_IMAGE="${PERSONA_NC_IMAGE:-nextcloud:33@sha256:df735d59202b74546ca4f935ec860d8397995a6e4e353926c4876547f6c6c4a5}"
PG_IMAGE="${PERSONA_PG_IMAGE:-postgres:16-alpine@sha256:721873c34ceb9f8d8fc265984940dc982404c105f19ad51be9fdc5970a6080ea}"
REF="${REF:-HEAD}"
GAMIFICATION="${GAMIFICATION:-off}"
case "$GAMIFICATION" in on|off) ;; *) echo "GAMIFICATION must be on or off" >&2; exit 2 ;; esac
CMD="${1:-all}"

mkdir -p "$OUT"
chmod 700 "$OUT"

occ() { docker exec -u www-data -w /var/www/html "$NC" php occ "$@"; }

down() {
	[ -f "$STATE" ] || { echo "persona-sweep: no instance (no $STATE)"; return 0; }
	# shellcheck disable=SC1090
	. "$STATE"
	local ok=1 ids
	ids="$(docker ps -aq --filter "label=$LABEL")" || ok=0
	if [ -n "$ids" ]; then
		# shellcheck disable=SC2086
		docker rm -f -v $ids >/dev/null || ok=0
	fi
	ids="$(docker network ls -q --filter "label=$LABEL")" || ok=0
	if [ -n "$ids" ]; then
		# shellcheck disable=SC2086
		docker network rm $ids >/dev/null || ok=0
	fi
	if [ -n "$(docker ps -aq --filter "label=$LABEL")$(docker network ls -q --filter "label=$LABEL")" ]; then ok=0; fi
	[ "$ok" = 1 ] || { echo "persona-sweep: CLEANUP FAILED: resources with label $LABEL" >&2; return 3; }
	rm -f -- "$STATE"
	echo "persona-sweep: instance removed"
}

up() {
	if [ -f "$STATE" ]; then echo "persona-sweep: an instance is already up ($STATE) — run '$0 down' first" >&2; exit 2; fi
	local run_id work pass
	run_id="$$-$RANDOM$RANDOM"
	LABEL="learning-persona.run=$run_id"
	NET="learning-persona-net-$run_id"
	DB="learning-persona-db-$run_id"
	NC="learning-persona-nc-$run_id"
	work="$(mktemp -d "${TMPDIR:-/tmp}/learning-persona.XXXXXX")"
	pass() { head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 24; }
	local db_pass; db_pass="$(pass)"
	# The state file is written first: a failed setup can still be torn down with `down`.
	umask 077
	cat >"$STATE" <<-EOF
		LABEL='$LABEL'
		NET='$NET'
		DB='$DB'
		NC='$NC'
		GAMIFICATION='$GAMIFICATION'
		REF='$REF'
		EOF

	mkdir -p "$work/repo"
	git -C "$REPO_ROOT" archive "$REF" app | tar -x -C "$work/repo"
	[ -f "$work/repo/app/appinfo/info.xml" ] || { echo "snapshot of $REF has no app/appinfo/info.xml" >&2; exit 2; }
	local commit; commit="$(git -C "$REPO_ROOT" rev-parse --short "$REF")"

	echo "==> containers $DB + $NC ($commit, gamification $GAMIFICATION)"
	docker network create --label "$LABEL" "$NET" >/dev/null
	docker create --name "$DB" --label "$LABEL" --network "$NET" \
		-e POSTGRES_USER=nc -e POSTGRES_PASSWORD="$db_pass" -e POSTGRES_DB=nextcloud "$PG_IMAGE" >/dev/null
	# Published on loopback only, on a port Docker picks.
	docker create --name "$NC" --label "$LABEL" --network "$NET" -p 127.0.0.1::80 \
		--entrypoint sleep "$NC_IMAGE" infinity >/dev/null
	docker start "$DB" "$NC" >/dev/null
	local ready=0
	for _ in $(seq 90); do
		if docker exec "$DB" pg_isready -h 127.0.0.1 -U nc -d nextcloud -q 2>/dev/null; then ready=1; break; fi
		sleep 1
	done
	[ "$ready" = 1 ] || { echo "PostgreSQL did not come up" >&2; exit 1; }

	echo "==> install Nextcloud + enable learning"
	local admin_pass; admin_pass="$(pass)"
	docker exec "$NC" sh -euc 'cp -a /usr/src/nextcloud/. /var/www/html/ && mkdir -p /var/www/html/data'
	docker cp "$work/repo/app/." "$NC:/var/www/html/apps/learning"
	rm -r -- "$work"
	docker exec "$NC" chown -R www-data:www-data /var/www/html
	occ maintenance:install -n --database=pgsql --database-host="$DB" --database-name=nextcloud \
		--database-user=nc --database-pass="$db_pass" --admin-user=admin --admin-pass="$admin_pass" >/dev/null
	local port; port="$(docker port "$NC" 80/tcp | head -n1 | sed 's/.*://')"
	local url="http://127.0.0.1:$port"
	occ config:system:set trusted_domains 0 --value=localhost >/dev/null
	occ config:system:set trusted_domains 1 --value=127.0.0.1 >/dev/null
	occ config:system:set overwrite.cli.url --value="$url" >/dev/null
	occ config:system:set default_language --value=en >/dev/null
	occ config:system:set default_locale --value=en_GB >/dev/null
	# One browser logs in as four people from 127.0.0.1 — that must not be throttled.
	occ config:system:set auth.bruteforce.protection.enabled --value=false --type=boolean >/dev/null
	occ app:disable firstrunwizard >/dev/null 2>&1 || true
	occ app:enable learning
	docker exec -d "$NC" apache2-foreground

	echo "==> people"
	occ group:add learning-instructors >/dev/null
	occ group:add safety-staff >/dev/null
	local instructor_pass learner_pass
	instructor_pass="$(pass)"; learner_pass="$(pass)"
	docker exec -u www-data -w /var/www/html -e OC_PASS="$instructor_pass" "$NC" \
		php occ user:add --password-from-env --display-name="Ines Instructor" -g learning-instructors instructor >/dev/null
	local i
	for i in 1 2 3; do
		docker exec -u www-data -w /var/www/html -e OC_PASS="$learner_pass" "$NC" \
			php occ user:add --password-from-env --display-name="Learner $i" -g safety-staff "learner$i" >/dev/null
	done
	for u in admin instructor learner1 learner2 learner3; do
		occ user:setting "$u" core lang en >/dev/null
		occ user:setting "$u" core locale en_GB >/dev/null
		occ user:setting "$u" learning onboarding_acknowledged yes >/dev/null
		occ user:setting "$u" learning onboarding_declined yes >/dev/null
	done

	echo "==> Learning settings: signing key, gamification $GAMIFICATION, AI $([ "$GAMIFICATION" = on ] && echo 'on (seed: Ollama, nothing answers)' || echo off)"
	occ learning:cert:init-issuer
	occ config:app:set learning gamification_enabled --value="$([ "$GAMIFICATION" = on ] && echo yes || echo no)" >/dev/null

	cat >>"$STATE" <<-EOF
		URL='$url'
		COMMIT='$commit'
		ADMIN_PASS='$admin_pass'
		INSTRUCTOR_PASS='$instructor_pass'
		LEARNER_PASS='$learner_pass'
		EOF

	ready=0
	for _ in $(seq 60); do
		if curl -fsS "$url/status.php" 2>/dev/null | grep -q '"installed":true'; then ready=1; break; fi
		sleep 1
	done
	[ "$ready" = 1 ] || { echo "Nextcloud did not answer on $url" >&2; exit 1; }

	echo "==> seed (course, pools, certification) as the instructor"
	PERSONA_STATE="$STATE" node "$HERE/seed.mjs"

	# GitHub #66: hint, feedback, intro and dialog for the seeded media question. The fixture bundle
	# also describes demo-q2, which the seed does not import; an unknown exam_key fails the import,
	# so the bundle goes in with demo-q1 only. From the same snapshot as the app ($REF), not the
	# working copy.
	echo "==> question media (fixture demo-q1) into the media pool"
	local media_pool qm
	media_pool="$(node -e 'process.stdout.write(String(JSON.parse(require("fs").readFileSync(process.argv[1], "utf8")).media.poolId))' "$OUT/seed.json")"
	qm="$(mktemp -d "${TMPDIR:-/tmp}/learning-persona-qm.XXXXXX")"
	git -C "$REPO_ROOT" archive "$REF" app/tests/fixtures/question-media/bundle | tar -x -C "$qm"
	mkdir -p "$qm/bundle"
	mv "$qm/app/tests/fixtures/question-media/bundle/demo-q1" "$qm/bundle/"
	node -e '
		const fs = require("fs")
		const m = JSON.parse(fs.readFileSync(process.argv[1], "utf8"))
		m.questions = m.questions.filter((q) => q.exam_key === "demo-q1")
		if (m.questions.length !== 1) throw new Error("fixture manifest has no demo-q1")
		fs.writeFileSync(process.argv[2], JSON.stringify(m))
	' "$qm/app/tests/fixtures/question-media/bundle/manifest.json" "$qm/bundle/manifest.json"
	docker cp "$qm/bundle/." "$NC:/tmp/persona-question-media"
	rm -r -- "$qm"
	docker exec "$NC" chown -R www-data:www-data /tmp/persona-question-media
	occ learning:import-question-media "$media_pool" /tmp/persona-question-media
	echo "persona-sweep: up at $url (state in $STATE)"
}

sweep() {
	[ -f "$STATE" ] || { echo "persona-sweep: no instance — run '$0 up' first" >&2; exit 2; }
	local mode run
	mode="$(sed -n "s/^GAMIFICATION='\(.*\)'$/\1/p" "$STATE")"
	run="$OUT/gamification-${mode:-off}"
	if [ -n "${PERSONA_STATIONS:-}" ]; then
		rm -rf -- "$run/shots-filtered" "$run/findings-filtered.json" "$run/report-filtered.md" "$run/stations-filtered.json"
		mkdir -p "$run/shots-filtered"
	else
		rm -rf -- "$run/shots" "$run/downloads" "$run/findings.json" "$run/report.md" "$run/stations.json"
		mkdir -p "$run/shots"
	fi
	rm -rf -- "$OUT/auth" "$OUT/pw-results"
	mkdir -p "$OUT/auth" "$run/downloads"
	local rc=0
	# Playwright and its test runner come from app/node_modules (no second install); NODE_PATH lets
	# the files here resolve @playwright/test from there.
	(cd "$REPO_ROOT/app" && PERSONA_STATE="$STATE" NODE_PATH="$REPO_ROOT/app/node_modules" \
		npx playwright test -c "$HERE/sweep.config.cjs") || rc=$?
	PERSONA_STATE="$STATE" node "$HERE/report.mjs" || rc=$?
	echo "persona-sweep: report in $run/report.md, fresh-eyes input in $run/stations.json (see $HERE/FRESH-EYES.md)"
	return "$rc"
}

case "$CMD" in
	up) up ;;
	sweep) sweep ;;
	down) down ;;
	all)
		[ "${KEEP:-}" = 1 ] || trap 'down || true' EXIT
		up
		sweep
		;;
	*) echo "usage: $0 [all | up | sweep | down]" >&2; exit 2 ;;
esac

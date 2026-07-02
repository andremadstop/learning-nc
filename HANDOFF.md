---
created: 2026-07-02T02:00:00+02:00
milestone: v5.2.0 Pflichtschulung
branch: feature/v5.2.0-pflichtschulung
resume_at: Phase 163 (planning) — Teamleiter-RBAC-Reports + DSGVO Art.20
author: Claude (Opus 4.8 1M) — autonomous run
---

# HANDOFF — v5.2.0 „Pflichtschulung", resume bei Phase 163

> Fresh-context-Übergabe im autonomen Milestone-Lauf. Alles committet, working tree clean.
> **Release-Entscheidung (2026-07-01): „weiter bis v5.2.0 komplett" — 162→163→164 autonom, dann Go für Release-Akt.**

## 🎛 Autonomie-Vertrag (vom User freigegeben)
- **Ohne Rückfrage durchlaufen.** An Phasengrenzen **informieren** (nicht fragen). **Stoppen NUR bei:** (1) Secret/Extern nötig, (2) Release/Prod/`main`-Merge/Tag/App-Store (irreversibel → am Milestone-Ende vorlegen), (3) echter Blocker.
- **3-KI-Team:** Claude orchestriert. **Gemini** (`fabric --model gemini-2.5-pro < input`) = Design/Completeness-Review der Pläne VOR dem Bauen. **Codex** (`codex exec --sandbox read-only "<prompt>" < /dev/null`) = Security-Review NACH dem Bauen (Pflicht bei security-kritischen Phasen). Gate-Reihenfolge: fabric→Gemini (Plan) … bauen … Codex (Code).

## 📍 Stand — 3/5 Phasen (60%)
- **160** Foundation (AUDIT-01..03 + ASSIGN) — ✅ COMPLETE (12/12), commit `f71ce32`.
- **161** Audit Hardening (AUDIT-04..09) — ✅ COMPLETE (6/6), commit `d14e193`.
- **162** Video-/Material-Gating (VIDEO-01..09, DSGVO-04) — ✅ **COMPLETE 2026-07-02.** 4 Pläne/3 Waves. Verifier 27/27 code-verified, 7 live/visual → human-verify. Grumpy-Codex **3 Pässe, alle Funde gefixt** (2 BLOCKER heartbeat-fraud + courseId-gate-bypass, 1 HIGH missing-enrollment, 1 MED ratelimit, 2 PARTIAL resume+short-video, 1 LOW access-order) mit lockenden Tests. PHPStan L5 clean, PHPUnit 256/874. Migration **009500 live** (info.xml **5.2.0.2**). Commits: 356e4a6, 8c70a69, 2b509cb, 146c42a, 3a84baf, e5f713a, 950e2cc, 3142c83, 0c48b43, d4ec1aa, f0e9fe3, f706e88, 053fc94, f7f23ed, 9f9c688, 7f30ea7, 9bd8b33, 3650a76, b46f003.
- devcloud: `learning 5.2.0.2` (Dev-Bump für Migration 009500; **beim Release auf 5.2.0 zurücksetzen**). PG16.
- LPIC-1-Prüfung Andre **03.07.** (Ghostline v5.1.0 pausiert, eigener Branch, unangetastet).

## ✅ Phase 162 — was live steht (AWO-Kern-Blocker gelöst)
- **Schema (Migration 009500):** `oc_learning_course_videos` (Registry: source_type/video_ref/duration_seconds nullable/subtitle_ref/sort_order), `oc_learning_video_progress` (user_id/content_id/intervals_json/covered_pct/last_ping_ts/completed_at, **UNIQUE(user_id,content_id) nur** → Recert-Naht für 164 intakt), `oc_learning_courses.video_gate_enabled` (bool default false).
- **VideoProgressService** = Anti-Fraud-Completion-Engine: server-merged intervals ≥95%; `<5s`-Pings verworfen; **per-Ping Wall-Clock-Rate-Cap** (Ping kreditiert höchstens real verstrichene Zeit); erster Ping capped auf min(15s, duration·0.5) `<95%` → kein One-Shot-Complete; `coveredPct` hart auf 1.0; `markComplete` emittiert **nur {course_id, content_id}** in die Audit-Chain; CAS auf last_ping_ts statt FOR UPDATE (NC hat kein forUpdate()).
- **VideoStreamController** Range-206/416, IDOR-Gate (`assertEnrolledInCourse` vor jedem Byte, Pfad nur aus Registry, Instructor-Namespace-fopen), `UserRateLimit(120/60)`.
- **TrainingService::startSession** Gate: läuft **nach hasPoolAccess, VOR Resume + Insert**, Scope rein aus `getGatedCourseIdsForPool($poolId,$userId)` (enrolled+gated) → **courseId-Weglassen umgeht nicht mehr**.
- **VideoProgressController**: heartbeat/complete/document-read (alle enrollment-guarded → 403) + `courseStatus` (student-read, leak-frei: kein video_ref/intervals).
- **Frontend:** WCAG-2.1-AA `VideoPlayer.vue`, `VideoConsentOverlay.vue` (Art.13, kein Preload vor Consent, youtube-nocookie+dnt=1/Vimeo dnt=1), `TrainingPrivacyNotice.vue` (Art.13 Schulungsbeginn), `CourseTabLernraum.vue` Student-Gate-UI + „Gelesen". JS **gebaut + deployed**.
- **Scope-Grenze verifiziert:** `learning_sessions` wird an genau einer Stelle inserted — Duel/Gameshow/Leitner nutzen separate Tabellen → **können das Gate nicht umgehen** (bewusste, belegte Grenze; nur startSession/Training+Exam = Cert-Pfad ist gegatet).

## 🔬 OFFEN — Andres 162-Durchlauf (7 human-verify, KEIN Blocker)
Nach `--js-only`-Deploy (bereits erfolgt) im Browser/curl:
1. **curl Range 206 + 416** über Relay-Storage: `curl -I -H "Range: bytes=0-1023" -b "<session>" …/apps/learning/api/video-stream/<contentId>` → 206 + Content-Range; malformed → 416.
2. **seek-to-99% → Gate bleibt zu** (Quiz 403).
3. **Consent no-preload:** DevTools-Network — 0 youtube/vimeo-Requests vor Klick; danach youtube-nocookie+dnt=1.
4. **WCAG:** Screenreader liest play/pause (aria-live), AA-Kontrast, kein Autoplay.
5. **Art.13-Text** im Schulungsbeginn-Notice korrekt.
6. **„Gelesen"-Flip** im Browser + Playwright-Live-Run (`E2E_VIDEO_COURSE_ID` + Student-Creds seeden).
7. **courseStatus IDOR** live: enrolled 200 (leak-frei) / non-member 403.
> Volle live-Auth braucht Vault-User-Passwörter + Playwright-Login (test-api.sh-Muster). Bewusst deferred — Security ist code-verified (27/27) + unit-gelockt + 3× Codex.

## ▶ RESUME — nächste Schritte
1. **`/gsd:plan-phase 163`** (Teamleiter-RBAC-Reports + Art.20). Frischer Kontext empfohlen (`/clear` zuerst).
2. Per-Phase-Pipeline fahren (s.u.). **163 ist RBAC/IDOR-kritisch → Codex-Review Pflicht.**
3. Dann 164 (Re-Zert). Milestone-Ende → **Release-Akt dem User vorlegen** (info.xml→5.2.0, CHANGELOG, ff `main`, Tag, Codeberg, App-Store) — NICHT autonom.

## 🔁 Per-Phase-Pipeline (autonomer Loop)
`plan-phase` (CONTEXT aus User-Entscheidungen schreiben → gsd-phase-researcher → VALIDATION.md aus Template → gsd-planner → **parallel** gsd-plan-checker + Gemini-Plan-Review → 1 gezielte Revision) → `execute-phase` (Executor je Plan **write+commit only**, Wave-parallel) → **zentrales Gate 1** (Orchestrator: deploy + occ upgrade + PHPStan + PHPUnit) → **Codex-Security-Review** (security-kritische Phasen; ggf. mehrere Pässe bis SHIP) → gsd-verifier → complete (STATE/ROADMAP/REQUIREMENTS **manuell**).

## ⚠ KRITISCHE Mechanik (hart gelernt)
- **Container-Test-Teilung:** kein lokales PHP. PHPStan L5 + PHPUnit NUR im devcloud-Container. **Executor schreiben+committen nur** (kein deploy/ssh/php). **Orchestrator deployt + fährt Gate 1 zentral pro Wave.**
- **Deploy:** `./scripts/deploy-prod.sh --php-only` (deploy lib/ + PHPStan), `--js-only` (build+deploy JS), `--test` (PHPStan + volle PHPUnit; synct tests/). Der „Class OCP\AppFramework\App not found"-Fatal beim Deploy-Verify = Artefakt, **IGNORIEREN**.
- **PhpUnitStubs** (`app/tests/Support/PhpUnitStubs.php`): neue OCP-Fläche MUSS ergänzt werden (diese Phase ergänzt: `Http::STATUS_CREATED/NO_CONTENT/PARTIAL_CONTENT`). Tests unter `app/tests/Unit/` (großes U), Namespace `OCA\Learning\Tests\Unit\...`. NICHT `IOutput`/`ICallbackResponse` stubben (Streaming-Controller = manuell/curl).
- **Migrationen:** brauchen info.xml `<version>` > installiert + `occ upgrade`. info.xml ist **5.2.0.2**. Neue Migration → bumpen (5.2.0.3 …) + `occ upgrade`. Schema: `ssh relais 'docker exec devcloud-db psql -U oc_admin -d nextcloud -c "\d oc_learning_..."'`. QueryBuilder `from()` UNPREFIXED (`learning_x` → NC hängt `oc_` an). **NC IQueryBuilder hat KEIN forUpdate()** → CAS-Pattern nutzen.
- **gsd-tools state/roadmap Mutations-Commands KORRUMPIEREN Frontmatter** → STATE.md/ROADMAP.md/REQUIREMENTS.md **manuell** editieren.
- **Codex ist paranoid + iterativ** — findet pro Pass „noch eine Sache". Bei security-kritischem Code mehrere Pässe fahren bis SHIP; jeden Fund mit lockendem Regression-Test schließen (die alten Tests waren grün während das Gate offen war!).
- **Fish-Shell:** Backticks in Commit-Messages → in Datei schreiben + `git commit -F`.

## 🎯 Phase-163-Specifics (NÄCHSTE PHASE)
**Reqs:** RBAC-02/03/04, DSGVO-02. **RBAC/IDOR-kritisch → Codex Pflicht.**
- Kern: `CertificateReportService::getGroupReport()` IDOR-safe auf `learning_oversight` (in P160 angelegt). **`assertTeamLeadForGroup` FIRST + DB-Level-Filter** (`WHERE user_id IN (group members)`), kein Post-Filtering.
- Erinnerungen via `INotificationManager` (email-null-safe); `IMailer` additiv nur wo Email non-null.
- DSGVO-02 Art.20: Nutzer exportiert eigene Daten (Datenportabilität).
- Success-Kriterien in ROADMAP.md §Phase 163.

## 📄 Artefakte
- Phase 162: `.planning/phases/162-video-material-gating/` (CONTEXT, RESEARCH, VALIDATION, 4×PLAN+SUMMARY, VERIFICATION 27/27). Codex-Reviews: `scratchpad/162-codex-review.md`, `162-codex-reverify-out.md`, `162-codex-reverify2-out.md`.
- REQUIREMENTS.md: 41 Reqs, **28 abgehakt** (160:12 + 161:6 + 162:10). ROADMAP.md: 160/161/162 ✓.

## 🏢 Parallel: AWO/Jan (Issue #20)
- Jan wartet auf **genau dieses Video-Gating (162)** → jetzt gebaut. **Rückmeldung an Jan sobald Andre den 162-Durchlauf bestätigt hat** (die 7 human-verify). App↔UG-Kanal: `~/ObsidianVaults/Personal/Projekte/Learning-NC/App-Requirements-Compliance-Business.md`.
- AWO braucht **Betriebsvereinbarung** (BetrVG §87 Abs.1 Nr.6) vor Prod — transiente Segmente (162) sind die technische Mitigation; dem Kunden kommunizieren.

## ⓘ Sonstiges
- GitNexus-Index stale (non-blocking; direkt gereadet/gegreppt).
- Codex-Model = gpt-5.5 (reasoning high). Gemini via fabric = `gemini-2.5-pro`.

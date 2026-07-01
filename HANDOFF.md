---
created: 2026-07-01T20:15:00+02:00
milestone: v5.2.0 Pflichtschulung
branch: feature/v5.2.0-pflichtschulung
resume_at: Phase 162 (planning) — Video-/Material-Gating
author: Claude (Opus 4.8 1M) — autonomous run
---

# HANDOFF — v5.2.0 „Pflichtschulung", resume bei Phase 162

> Fresh-context-Übergabe im autonomen Milestone-Lauf. Alles committet, working tree clean bei `d14e193`.
> **Release-Entscheidung getroffen (2026-07-01): User will „weiter bis v5.2.0 komplett" — 162→163→164 autonom, dann Go für Release-Akt.**

## 🎛 Autonomie-Vertrag (vom User freigegeben — „YOLO / vollständig autonom bis fertig")
- **Ohne Rückfrage durchlaufen.** An Phasengrenzen **informieren** (nicht fragen). **Stoppen NUR bei:** (1) Secret/Extern nötig (Forgejo-Anker-Token), (2) Release/Prod/`main`-Merge/Tag/App-Store (bewusste irreversible Akte → am Milestone-Ende dem User vorlegen), (3) echter Blocker.
- **3-KI-Team-Modell:** Claude orchestriert. **Gemini** (`fabric --model gemini-2.5-pro < input`) = Design/Completeness-Review der Pläne VOR dem Bauen. **Codex** (`codex exec --sandbox read-only "<prompt>" < /dev/null`) = Security-Review des security-kritischen Codes NACH dem Bauen. Gate-Reihenfolge je Phase: fabric→Gemini→Codex.

## 📍 Stand
- Milestone v5.2.0, Branch `feature/v5.2.0-pflichtschulung` (off `main`). **2/5 Phasen (40%).**
- **Phase 160 COMPLETE + verifiziert (12/12)**, commit `f71ce32`. Audit-Hash-Chain + Assignment-Fundament.
- **Phase 161 (Audit Hardening) COMPLETE + verifiziert (6/6 automated)**, commit `d14e193`. Ed25519-Checkpoints (Migration 009302) + Forgejo-Anker + `occ audit:verify` + Auditor-Export + Liveness-Widget + Fork-Runbook. **Grumpy-Codex: 7/7 Security-Funde gefixt** (F1 prev_hash-BLOCKER..F7). Gates: PHPStan L5 clean, PHPUnit 222/768, live occ-verify exit 0. **3 human-verify offen** (Andres Durchlauf: Live-Checkpoint-Mint auf non-empty Chain, Overdue-Banner-DOM, Export-UI-Optik).
- devcloud: `learning 5.2.0.1` (Dev-Bump für Migration 009302; **beim Release auf 5.2.0 zurücksetzen**). PG16. Gruppe `learning-auditors` angelegt.
- LPIC-1-Prüfung Andre **03.07.** (Ghostline v5.1.0 pausiert, eigener Branch, unangetastet).

## ▶ RESUME — nächste Schritte
1. **`/gsd:plan-phase 162`** (Video-/Material-Gating + DSGVO Art.13, VIDEO-01..09 + DSGVO-04). Frischer Kontext empfohlen (`/clear` zuerst) — 162 ist die komplexeste Phase.
2. Pro Phase die **Pipeline** fahren (s.u.). 162 ist security-kritisch (Streaming-Auth, Gating) → **Codex-Review Pflicht**.
3. Dann 163 (RBAC-Reports), 164 (Re-Zert). Milestone-Ende → **Release-Akt dem User vorlegen** (info.xml→5.2.0, CHANGELOG, ff `main`, Tag, Codeberg, App-Store) — NICHT autonom.

## 🔁 Per-Phase-Pipeline (der autonome Loop)
`plan-phase` (gsd-phase-researcher konsolidiert vorhandene Research → VALIDATION.md manuell → gsd-planner → **parallel** gsd-plan-checker + Gemini-Plan-Review → 1 gezielte Revision) → `execute-phase` (gsd-executor je Plan, Wave-parallel) → **zentraler Gate 1** (deploy + PHPStan + PHPUnit) → **Codex-Security-Review** (bei security-kritischen Phasen) → gsd-verifier → complete (STATE/ROADMAP/REQUIREMENTS **manuell**).

## ⚠ KRITISCHE Mechanik (diese Session hart gelernt — nicht neu erleiden)
- **Container-Test-Teilung:** PHPStan L5 + PHPUnit laufen NUR im devcloud-Container (**lokal KEIN PHP-Binary**). Executor **schreiben+committen nur** (kein deploy/ssh/php-l → keine Parallel-Deploy-Races). **Orchestrator deployt + fährt Gate 1 zentral pro Wave.**
- **Deploy:** `./scripts/deploy-prod.sh --php-only` = deploy lib/ + PHPStan (deploy_php läuft VOR phpstan → Code ist deployed auch wenn PHPStan abbricht; der „Class OCP\AppFramework\App not found"-Fatal beim Deploy-Verify ist ein Artefakt, IGNORIEREN). `--test` = PHPStan + volle PHPUnit. **run_phpunit wurde diese Session gefixt** (synct jetzt ganzes tests/ in den Container; vorher nur 3 Support-Dateien → stale Tests).
- **PHPStan scannt nur lib/** (Migrationen + Tests exkludiert) → per-Wave-PHPStan inkrementell clean; RED-Stubs die Zukunfts-Klassen referenzieren brechen PHPStan NICHT. **PHPUnit-GREEN erst am Phasenende.**
- **Unit-Tests laufen gegen STUBS** (`app/tests/Support/PhpUnitStubs.php` = handgeschriebene OCP-Interfaces), NICHT echtes NC. Neue OCP-Fläche MUSS dort ergänzt werden, sonst fatalen die Tests (bisher ergänzt: IJobList, QueuedJob, TimedJob, ITimeFactory, IClientService/IClient/IResponse, createUser, getEMailAddress, IGroup, diverse QueryBuilder-Methoden). **Tests liegen unter `app/tests/Unit/` (großes U!), Namespace `OCA\Learning\Tests\Unit\...`** — NICHT `tests/unit`.
- **PHPUnit-Test-Fallen (diese Session getroffen):** Helper NIE `run()` nennen (kollidiert mit finaler `TestCase::run()`); Fixtures mit explizitem `null` brauchen `array_key_exists` statt `?? default` (sonst clobbert der Default das `null`); `context_json` wird als escapter JSON-String emittiert → im JSONL nach `\"key\":val` prüfen oder decoden.
- **Migrationen:** brauchen info.xml `<version>` > installiert um via `occ upgrade` zu laufen (kein `migrations:execute` in dieser NC). **info.xml ist auf `5.2.0.1`** (Dev-Bump für Migration 009302; **beim Release auf 5.2.0 zurücksetzen**). Neue Migration → info.xml bumpen (`5.2.0.2` etc.) + `occ upgrade`. Schema prüfen: `ssh relais 'docker exec devcloud-db psql -U oc_admin -d nextcloud -c "\d oc_learning_..."'`. **QueryBuilder `from()` UNPREFIXED** (`learning_x` — NC hängt `oc_` an; `oc_learning_x` → `oc_oc_...`-Crash).
- **gsd-tools state/roadmap Mutations-Commands KORRUMPIEREN Frontmatter** → STATE.md/ROADMAP.md **manuell** editieren. (init / roadmap get-phase / phase-plan-index sind read-only, safe.)

## 🎥 Phase-162-Specifics (NÄCHSTE PHASE — Video-/Material-Gating + DSGVO Art.13)
**Reqs:** VIDEO-01..09, DSGVO-04. **Security-kritisch → Codex-Review Pflicht.** Zuerst `/gsd:plan-phase 162` (Research existiert evtl. schon in `.planning/phases/`? prüfen; sonst gsd-phase-researcher).
- **Crux = server-seitiges Streaming:** `VideoStreamController` liefert Instructor-Video via `IRootFolder->getUserFolder(instructorId)->fopen()` + HTTP **Range 206** (partial content). Auth: der Stream-Endpoint MUSS gaten (Enrolment/Assignment prüfen) — kein direkter Files-Link. IDOR/Path-Traversal-Fläche → Codex-Fokus.
- **Gate server-seitig** in `TrainingService::startSession()` (o.ä.): ohne genügend Video-Progress kein Vorankommen. Client-Heartbeat (throttled) meldet Watch-Progress → `VideoProgressService` emittiert `course.video.completed` Compliance-Event (Event-Type in P160 AUDIT-03 definiert → landet in der Audit-Chain).
- **DSGVO Art.13 (DSGVO-04):** Vimeo/YouTube-nocookie-Embeds laden ERST nach explizitem Consent (kein Pre-Load, kein Cookie vorher). Consent-UX + Art.13-Transparenz-Text. SDKs via CDN (0 npm-Deps). Reine NC-Files-Videos brauchen keinen Consent.
- **DSGVO-transiente Segmente**, a11y (Untertitel/Keyboard), Heartbeat-Throttle gegen Progress-Fälschung.
- **⚠ AWO-Kern-Blocker:** Jan wartet genau auf dieses Feature → nach 162 Rückmeldung an ihn.
- **Prio-Frage offen an User** (beim Sessionstart stellen, falls nicht geklärt): Vimeo vs. YouTube-nocookie vs. reine NC-Files-Videos als Prio? Konkrete AWO-Video-Quelle? a11y-Muss-Level?

## 🗺 Restliche Phasen
- **160** Foundation (AUDIT-01..03 + ASSIGN) — ✅ COMPLETE (12/12), commit `f71ce32`
- **161** Audit Hardening (AUDIT-04..09) — ✅ COMPLETE (6/6 automated), commit `d14e193`
- **162** Video-/Material-Gating + DSGVO Art.13 (VIDEO-01..09, DSGVO-04) — **NÄCHSTE, s.o. „Phase-162-Specifics".**
- **163** Teamleiter-RBAC-Reports + Art.20 (RBAC-02..04, DSGVO-02) — `CertificateReportService::getGroupReport()` IDOR-safe auf `learning_oversight` (schon in P160 angelegt); assertTeamLeadForGroup FIRST + DB-Level-Filter.
- **164** Re-Zertifizierung + Retention + i18n (RECERT-01..07, DSGVO-03/05) — **RECERT-05 Guard-Redesign = PFLICHT-Codex-Review** (OP am offenen Cert-Herzen; `PassCriteriaService::emitPassEventIfFirst` prüft active_period_key statt „je bestanden"; `RecertPeriodCloseJob`). DST-safe `DateTimeImmutable::modify('+1 year')`. Betriebsvereinbarungs-Hinweis für AWO.

## 🏢 Parallel: UG-Session (Business-Layer)
- Compliance-Schulung-as-a-Service, App bleibt FOSS. **App↔UG-Kanal:** `~/ObsidianVaults/Personal/Projekte/Learning-NC/App-Requirements-Compliance-Business.md` (hat „⬅ Rückkanal-Update" mit AVV/Betriebsrat/Barrierefreiheit/Retention/Auditor-Export). UG-Konzept kanonisch: `UG-Souverain/Strategie/strategie/32-Compliance-Schulung-Service-Konzept.md`.
- **Jan (AWO Issue #20)** hat geantwortet, wird warm gehalten → Rückmeldung sobald Video-Gating (Phase 162) steht.

## 📄 Artefakte
- Research: `.planning/research/{STACK,STACK-FOUNDATIONS,FEATURES,ARCHITECTURE,PITFALLS,SUMMARY}.md` (v5.2.0). Ghostline-Research archiviert unter `research/_v5.1.0-ghostline-archive/`.
- Reviews: `.planning/reviews/2026-07-01-gemini-v52-{concept,roadmap}-review.md`.
- Phase 160: `.planning/phases/160-foundation-audit-assignment/` (6 PLAN+SUMMARY, VERIFICATION 12/12).
- Phase 161: `.planning/phases/161-audit-hardening/` (6 PLAN+SUMMARY, RESEARCH, VALIDATION, VERIFICATION 6/6). Codex-Review: `scratchpad/161-codex-security.md`.
- REQUIREMENTS.md: 41 Reqs, **18 abgehakt** (Phase 160: 12 + Phase 161: 6). ROADMAP.md: Phase 160+161 ✓.

## ⓘ Sonstiges
- GitNexus-Index stale (non-blocking; direkt gereadet/gegreppt statt reindext).
- Codex-Model = gpt-5.5 (reasoning high). Gemini via fabric = `gemini-2.5-pro` (stabil; 3.x-Previews vorhanden aber ungenutzt).

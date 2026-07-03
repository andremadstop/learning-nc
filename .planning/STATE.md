---
gsd_state_version: 1.0
milestone: v5.2.0
milestone_name: "v5.2.0 Pflichtschulung"
current_phase: 164
current_plan: "Phase 164 Plan 04 ✓ COMPLETE + post-impl Codex review SHIP (2 Pässe, 7 Findings → 3 Fix-Commits) 2026-07-03 (4/7 plans). Next: 164-05 (RecertPeriodCloseJob impl)."
status: "v5.2.0 Pflichtschulung — Phase 164 aktiv (4/7 Pläne). 164-04 + Post-Impl-Review FERTIG: Codex Pass 1 fand 2 BLOCKER (closePeriod nicht idempotent + non-atomic strand) + 3 HIGH (cert-ohne-COURSE_PASSED, days/months-Disconnect→stilles 12-Monats-Default, PERIOD_CLOSED PII) + 2 MED (EOM-Overflow, alter Pfad ohne Guard). Alle 7 gefixt (closePeriod CAS-Gate auf certId + EINE Transaktion; evaluate() outer TX um issue+emit+markPassed; notify best-effort; computeExpiry legacy-days-Fallback KEIN implizites 12M-Default; EOM-Clamp; @deprecated issueIfPassed). Pass 2: VERDICT SHIP, alle FIX-CONFIRMED. Gate 1: PHPUnit 302 Tests, nur 3 erwartete Wave-RED (164-05/06/07). ⚠ closePeriod-SIGNATUR GEÄNDERT: + int $certId (Job muss certId aus seiner Expiry-Query liefern)."
stopped_at: "Post-Impl-Review SHIP (Commits 4b5994e, 27c06a7, 0563f04). Nächster Schritt: 164-05 RecertPeriodCloseJob (closeExpiredPeriods: Query auf learning_certificates WHERE active_idem_key IS NOT NULL AND expires_at < now-grace — user_id/course_id direkt von Cert-Row, KEIN idemKey-Parsing; dann closePeriod(type,id,courseId,certId))."
last_updated: "2026-07-03"
last_activity: "2026-07-03 — 164-04 Post-Impl-Codex-Review: 2 Pässe → SHIP. 11 neue Locking-Tests. cert_validity_months durch updateCertConfig-API + jsonSerialize verdrahtet (Months-UI-Feld → 164-07 mit i18n; Footgun: UI kann months nicht auf NULL zurücksetzen — bei UI-Bau Clear-Option vorsehen)."
progress:
  total_phases: 5
  completed_phases: 4
  total_plans: 24
  completed_plans: 21
  percent: 80
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-07-01)

**Core value:** Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung — fuer Einzellerner und Kursgruppen.
**Current focus:** v5.2.0 Pflichtschulung — Video-Gating, Teamleiter-Reports, Re-Zertifizierung, Audit-Hash-Chain. AWO-Lead (Jan Knizek, #20).

## Current Position

Phase: 164 — Re-Zertifizierung + Retention + i18n Parity (in progress, 4/7 Pläne).
Plan: 164-04 ✓ COMPLETE inkl. Post-Impl-Codex-Review (2 Pässe → SHIP; 7 Findings gefixt: 4b5994e, 27c06a7, 0563f04). Next: 164-05 (RecertPeriodCloseJob).
Status: Gate 1 grün — PHPUnit 302 Tests / 1023 Assertions, nur die 3 erwarteten Wave-RED (testDoubleRunSingleRow→164-05, testOncePerThreshold→164-06, testAnonymizeKeepsChain→164-07). PHPStan: nur bekannte Skeleton-Transienten.
Last activity: 2026-07-03 — Post-Impl-Review-Härtung: closePeriod(+certId) CAS+TX, evaluate() outer TX (cert⟺COURSE_PASSED atomar), notify best-effort, computeExpiry ohne implizites 12M-Default (legacy days-Fallback), EOM-Clamp, issueIfPassed @deprecated.

Progress: ████████░░ 80% (4/5 phases complete)

## Paused Milestone (resume nach LPIC-Prüfung 03.07.)

**v5.1.0 Ghostline** — Phase 158 (Akt 1 K3 Core) gebaut + auf devcloud gelistet; offen: Andrés Live-Durchspielen (Gate) + Phase 159 + teach-then-test-Lehr-Schicht (Spec wartet auf Review). Branch `feature/v5.1.0-ghostline` (nicht gemergt). Alles committet, working tree war clean.
Handoff: `.planning/HANDOFF-2026-07-01-ghostline-pause.md`. Requirements: `milestones/v5.1.0-REQUIREMENTS.md`. Memory: `project_ghostline_v51.md`.

## Accumulated Context

### Vorheriger Milestone
v5.0.0 Certification-as-a-Service — shipped 2026-06-28, Tag v5.0.0, live. 30/30 v1-Requirements.
Archiv: `.planning/milestones/v5.0.0-ROADMAP.md` + `v5.0.0-REQUIREMENTS.md`.
**Relevant für v5.2.0:** `CertificateReportService.getCourseReport()` (course-instructor-scoped, DSGVO-safe CSV), `RoleService` (global instructor/student), `Certificate` mit `expiry_date`. v5.2.0 erweitert genau diese.

### Blockers/Concerns (carry-forward — KRITISCH)

- **TOOLING:** `gsd-tools state update-progress` / `record-session` / `roadmap update-plan-progress`
  korrumpieren STATE.md/ROADMAP.md-Frontmatter (milestone → v2.3, droppen Spalten).
  **STATE/ROADMAP manuell editieren; buggy gsd-tools-State/Roadmap-Mutationen MEIDEN.**
- **⛔ 162 BLOCKER (Backend-Follow-up nötig, offen):** `CourseVideoController::index`
  (`GET /api/courses/{id}/videos`) ist instructor-gated (`assertInstructorOfCourse`) → ein
  eingeschriebener **Student bekommt 403**, und es gibt **keinen GET für per-user Progress**
  (VideoProgressController ist POST-only). Folge: das 162-04-Student-Gate-UI („Pflichtinhalte"-Tab)
  erscheint für Studenten live nicht (graceful degrade), VIDEO-07/08 + Gate-Status sind live erst
  nach dem Follow-up end-to-end prüfbar. **Empfohlen:** student-facing `GET`, der für einen
  *enrolled* Studenten die Video-Registry + `covered_pct`/`completed` liefert (reuse
  `CourseService::assertEnrolledInCourse` + `VideoProgressMapper::findByUserAndContent`),
  PHPStan L5 + Codex-Security-Pass (IDOR-Surface). Frontend (`normalizeVideo`) ist bereits
  forward-kompatibel (snake/camel + covered_pct/completed). Nicht in 162-04 gepatcht: Rule 4
  (out-of-ownership authz, kein lokales PHP, benannte Codex-Review-Surface).

### Milestone-Kontext v5.2.0

- Auslöser: AWO-Sachsen-Lead Jan Knizek, GitHub Issue #20 (Kommentar 2026-07-01). Antwort gepostet (issuecomment-4851447663).
- Gap-Analyse (Code + NLM verifiziert): Cert + Compliance-Report existieren (v5.0.0), aber Report ist course-instructor-scoped (nicht teamleiter/gruppengefiltert); Video-Gating fehlt komplett; Re-Zert-Fundament (`expiry_date`) da, aber keine Erinnerung/Re-Enrollment.
- Video-Enforcement: NC-MP4 (voller Watch-Track, eigener Player) UND Vimeo/YouTube-Embed (Provider-JS-API) — beide, User-Entscheidung.
- Konkurrenz-Kontext: AWO nimmt für die akute 2000-Mann-Schulung Forma LMS (SCORM, Cert/Reporting nativ). Unser Vorteil = NC-nativ, kein Zweitsystem. Nicht feature-für-feature gegen Forma, sondern NC-native Pflichtschulung rund machen.
- UG-Business-Layer parallel (separate Session): Compliance-Schulung-as-a-Service, App bleibt FOSS. Handover an App-Session: `~/ObsidianVaults/Personal/Projekte/Learning-NC/App-Requirements-Compliance-Business.md` (3 🔴-Stack-Weichen). UG-Brief: `.../UG-Geschaeftsmodell-Compliance-Schulung.md`.

### Team-Modell (approved 2026-07-01) — 3 KI-Teammitglieder nach Stärke eingebunden
- **Claude:** Architektur/Orchestrierung/Security-Design, Gate-Owner.
- **Gemini/fabric** (`fabric --model gemini-2.5-pro < input`): Design/Spec/Completeness-Reviews — kriegt Konzept VOR dem Bauen (Requirements+Roadmap), Design-Review kniffliger Phasen, Pre-Live-UX-Review.
- **Codex** (`codex exec --sandbox read-only "<prompt>" < /dev/null`): Code/Security-Bugs (Kernstärke, fand 9 echte Bugs Phase 155), Bulk-Impl, Test-Generierung. Schwerpunkt: security-kritische Phasen (Audit-Hash-Chain, Video-Gate-Server-Enforcement, RBAC/IDOR, Crypto).
- Pre-Live-Gate-Reihenfolge je Phase: fabric → Gemini → grumpy Codex.

### v5.2.0 Scope-Entwicklung (nach UG-Handover, vor finalem Scoping)
6 Blöcke: (1) Assignment-Modell (Person/Gruppe→Kurs→Frist→Re-Zert-Zyklus, auf NC-Groups/LDAP — Substrat, 🔴UG#3); (2) Video-/Material-Gating (NC-MP4 via VideoStreamController hart, Vimeo/YT best-effort, DSGVO-transiente Segmente); (3) Teamleiter-RBAC-Reports (gruppengefiltert, View aufs Assignment); (4) Re-Zertifizierung (Guard-Redesign + Period-Close-Job); (5) Manipulationssicherer Audit-Trail (Hash-Chain + Ed25519-Anker, 🔴UG#1 NEU); (6) Username-Politur (kein-Mail-safe, CSV-occ-Command). Deferred: PGP/WKD-Cert-Hebel (🔴UG#2→v5.3+), portables Content-Format (Tür offen), Content-Authoring/Hosting/SCORM.

### v5.2.0 Roadmap Summary (2026-07-01)

**5 Phasen | 41 v1-Requirements | 100% Coverage**

| Phase | Name | Requirements | Key Constraint |
|-------|------|-------------|----------------|
| 160 | Foundation — Audit Hash-Chain + Assignment Schemas | AUDIT-01..03, ASSIGN-01..05, USER-01/02, DSGVO-01, RBAC-01 | RED-1+RED-3 must be iron-clad before anything else |
| 161 | Audit Hardening — Checkpoints + Anchor + Export | AUDIT-04..09 | AuditCheckpointService uses sodium direct, NOT SigningService |
| 162 | Video-/Material-Gating + DSGVO Art.13 | VIDEO-01..09, DSGVO-04 | Server-side enforcement only; zero new deps |
| 163 | Teamleiter-RBAC-Reports + DSGVO Art.20 | RBAC-02..04, DSGVO-02 | IDOR guard at DB level |
| 164 | Re-Zertifizierung + Retention + i18n Parity | RECERT-01..07, DSGVO-03, DSGVO-05 | RECERT-05 needs mandatory Codex security review |

**Critical decisions locked in research:**
- logComplianceEvent() MUST NOT swallow exceptions (unlike logEvent())
- learning_assignments: PLAIN composite index, NOT UNIQUE; active_period_key UNIQUE
- VideoStreamController: IRootFolder->getUserFolder($instructorId)->fopen() — NOT NC_SHARE_URL
- AssignmentService does NOT gate cert issuance (self-learners have no assignment row)
- DST-safe: DateTimeImmutable::modify('+1 year') ONLY
- AWO: Betriebsvereinbarung (BetrVG §87) required before production rollout

**164-04 decisions (RECERT-05, 2026-07-03):**
- Branch A UNFILTERED: findByUserAndCourse() returns newest cert regardless of revoked/expires_at — revoked cert still blocks auto-reissue (Codex GO). NEVER filter by active status.
- Branch B allow-list: ['assigned','in_progress','overdue'] — terminal states block by positive enumeration, NOT != 'passed'
- closePeriod raw SQL: UPDATE certificates WHERE active_idem_key=idemKey (expires_at=now-1 + active_idem_key=NULL) + UPDATE assignments + INSERT fresh row; no CertificateMapper in AssignmentService
- SC2: closePeriod MUST NOT set revoked=true; old URL reads 'expired' not 'withdrawn'

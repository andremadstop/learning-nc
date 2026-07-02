---
gsd_state_version: 1.0
milestone: v5.2.0
milestone_name: "v5.2.0 Pflichtschulung"
current_phase: 164
current_plan: "Phase 163 ✓ COMPLETE 2026-07-02 (7/7 plans). Next: Phase 164 (Re-Zertifizierung) — the last milestone phase. Not yet planned — start with /gsd:plan-phase 164."
status: "v5.2.0 Pflichtschulung — 4/5 Phasen (80%). Phase 163 (Teamleiter-RBAC-Reports + DSGVO Art.20) ✓ COMPLETE: observed-RED 5-wave build (RBAC-02/03/04 + DSGVO-02). RBAC-03 OversightMapper+RoleService reads learning_oversight; RBAC-02 getGroupReport (assert-first IDOR gate, DB-level WHERE user_id IN(members), set-difference vs IGroupManager names, opaque member_ref DTO — no raw uid/email); RBAC-04 mandatory compliance reminder (bypasses opt-out) + independent 2nd-IDOR guard (remindMember resolves member_ref only within expandGroup); DSGVO-02 own-cert export (raw VC-JWT, session-only) + kudos email masking. TeamLeadDashboard.vue (conditional, camelCase contract). Gates: PHPStan L5 clean, PHPUnit 281/281, Vitest 16/16, ESLint 0. **Grumpy-Codex 4 passes → SHIP** (BLOCKER email-in-uid via member_ref; dead-dashboard param/field contract; MEDIUM kudos third-party email; BLOCKER display_name==uid via safeDisplayName trim/case incl. pre-existing instructor cert-report) — each closed with a locking regression test. Verifier goal-backward → human_needed. Frontmatter manuell (gsd-tools-State-Commands MEIDEN)."
stopped_at: "Phase 163 COMPLETE + getrackt. Nächster Schritt: Phase 164 planen (/gsd:plan-phase 164) — letzte Milestone-Phase (Re-Zertifizierung), danach Release-Akt v5.2.0 dem User vorlegen. Live Gate 2 (test-api.sh IDOR curls) + UI/Notification-Bell = human-verify für Andres Durchlauf (Secret-Grenze: Vault-Admin-Creds nicht überschritten)."
last_updated: "2026-07-02"
last_activity: "2026-07-02 — Phase 163 COMPLETE: observed-RED 5-wave build (RBAC-02/03/04 + DSGVO-02), 4-pass grumpy-Codex security review → SHIP, goal-backward verify → human_needed. PHPStan L5 clean, PHPUnit 281/281, Vitest 16/16. Tracking manuell."
progress:
  total_phases: 5
  completed_phases: 4
  total_plans: 17
  completed_plans: 17
  percent: 80
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-07-01)

**Core value:** Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung — fuer Einzellerner und Kursgruppen.
**Current focus:** v5.2.0 Pflichtschulung — Video-Gating, Teamleiter-Reports, Re-Zertifizierung, Audit-Hash-Chain. AWO-Lead (Jan Knizek, #20).

## Current Position

Phase: 163 — Teamleiter-RBAC-Reports + DSGVO Art.20 ✓ COMPLETE (7/7 Pläne, 5 Waves). Next: Phase 164 (Re-Zertifizierung).
Plan: 7/7 — observed-RED build; grumpy-Codex 4 passes → SHIP; goal-backward verify → human_needed.
Status: RBAC-02/03/04 + DSGVO-02 gebaut + committed + zentral gate-verifiziert (PHPStan L5 clean, PHPUnit 281/281, Vitest 16/16, ESLint 0). Live Gate 2 (test-api.sh IDOR curls) + UI/Notification-Bell = human-verify (Andres Durchlauf; Secret-Grenze nicht überschritten).
Last activity: 2026-07-02 — Phase 163 COMPLETE (member_ref DTO, assert-first IDOR, mandatory reminder + 2nd-IDOR guard, DSGVO cert export, TeamLeadDashboard).

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

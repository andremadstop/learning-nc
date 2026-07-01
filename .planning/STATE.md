---
gsd_state_version: 1.0
milestone: v5.2.0
milestone_name: "v5.2.0 Pflichtschulung"
current_phase: 162
current_plan: null
status: "v5.2.0 Pflichtschulung. Phase 161 (Audit Hardening — Checkpoints + Anchor + Export + Liveness) COMPLETE + VERIFIED (6/6 must-haves automated, 2026-07-01). 6 Pläne/3 Waves, PHPStan L5 clean, PHPUnit 222/768 grün, Migration 009302 auf PG16 angewandt (info.xml dev-bump 5.2.0.1). Live: occ learning:audit:verify exit 0; Auditor-Export-Gate 403→200; getAdmin 5 audit_*-Keys HTTP 200. Grumpy-Codex-Security-Review: 7 Funde, ALLE gefixt (F1 prev_hash BLOCKER..F7 pubkey-length, commits 730261f..c3c75cd). 3 visuelle/live-daten-Items → Andres Durchlauf. NÄCHSTER SCHRITT: Release-Entscheidung dem User vorgelegt (v5.2.0 hat noch Phasen 162-164 offen) — NICHT autonom releasen. Frontmatter manuell gepflegt (gsd-tools-State-Commands MEIDEN)."
stopped_at: "Phase 161 complete + verified. Release-Entscheidung dem User vorgelegt. Bei Weiterbau: /gsd:plan-phase 162 (Video-/Material-Gating + DSGVO Art.13)."
last_updated: "2026-07-01"
last_activity: "2026-07-01 — Phase 161 gebaut+verifiziert: Ed25519-signierte Audit-Checkpoints (Migration 009302 + AuditCheckpointService + wöchentlicher Job) + Forgejo-Anker (off-by-default) + occ learning:audit:verify (6-Feld-Canonical, F1 prev_hash + F2 checkpoint-field-binding hardening) + Auditor-Export (JSONL+sig+HTML, gruppen-gated, PII-strip) + Liveness-Widget + Fork-Runbook. Gates: PHPStan L5 clean, PHPUnit 222/768, PG16 verifiziert, voller Codex-Security-Review (7/7 gefixt)."
progress:
  total_phases: 5
  completed_phases: 2
  total_plans: 6
  completed_plans: 6
  percent: 40
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-07-01)

**Core value:** Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung — fuer Einzellerner und Kursgruppen.
**Current focus:** v5.2.0 Pflichtschulung — Video-Gating, Teamleiter-Reports, Re-Zertifizierung, Audit-Hash-Chain. AWO-Lead (Jan Knizek, #20).

## Current Position

Phase: 161 — Audit Hardening (Checkpoints + Anchor + Export + Liveness) ✓ COMPLETE
Plan: 6/6 complete (next milestone phase: 162 — Video-/Material-Gating)
Status: Phase 161 verified (6/6 must-haves automated). Release-Entscheidung dem User vorgelegt (Phasen 162-164 noch offen).
Last activity: 2026-07-01 — Phase 161 gebaut+verifiziert+security-gehärtet (Codex 7/7)

Progress: ████░░░░░░ 40% (2/5 phases complete)

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

---
gsd_state_version: 1.0
milestone: v5.1.0
milestone_name: "v5.1.0 Ghostline"
current_phase: 158
current_plan: null
status: "v5.1.0 Ghostline — ROADMAP ERSTELLT (2026-06-30). 4-Researcher-Phase abgeschlossen (STACK/FEATURES/ARCHITECTURE/PITFALLS + SUMMARY in .planning/research/). 16 v1-Requirements definiert + freigegeben. Roadmap: 2 Phasen — 158 (K3 Core: Authoring & Korrektheit, 12 Reqs) + 159 (Retention/Material/Go-Live, 4 Reqs). NÄCHSTER SCHRITT: /gsd:plan-phase 158 (K3 Vertical Slice; 3-Node-Smoke → voller K3). Engine/Schema-Fakten im Spec §3+9. Frontmatter manuell gepflegt (gsd-tools-State-Commands MEIDEN)."
stopped_at: "new-milestone Flow KOMPLETT: PROJECT/STATE/REQUIREMENTS/ROADMAP gesetzt + committed (v5.0.0 archiviert 1b20721, milestone-start afabd24, requirements a81beb1, roadmap commit folgt). Research inline-synthetisiert (4. Agent war gehangen → neu gestartet → ok). Als Nächstes: plan-phase 158."
last_updated: "2026-06-30"
last_activity: "2026-06-30 — v5.0.0 milestone abgeschlossen + archiviert; v5.1.0 Ghostline gestartet (Brainstorm→Spec→new-milestone). Research-Phase als Nächstes."
progress:
  total_phases: 2
  completed_phases: 0
  total_plans: 0
  completed_plans: 0
  percent: 0
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-06-30)

**Core value:** Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung — fuer Einzellerner und Kursgruppen.
**Current focus:** v5.1.0 Ghostline — interaktives Story-Universum. Akt 1 LPIC-101 (Prüfung 03.07.).

## Current Position

Phase: 158 (Akt 1 — K3 Core) — geplant, noch nicht gestartet
Plan: — (nächster Schritt: /gsd:plan-phase 158)
Status: Roadmap erstellt (2 Phasen, 16 v1-Reqs). Bereit zum Planen von Phase 158.
Last activity: 2026-06-30 — Milestone v5.1.0 initialisiert (Research → Requirements → Roadmap komplett)

## Accumulated Context

### Vorheriger Milestone
v5.0.0 Certification-as-a-Service — shipped 2026-06-28, Tag v5.0.0, live + verify-release grün.
30/30 v1-Requirements. Archiv: `.planning/milestones/v5.0.0-ROADMAP.md` + `v5.0.0-REQUIREMENTS.md`.

### Blockers/Concerns (carry-forward — KRITISCH)

- **TOOLING:** `gsd-tools state update-progress` / `record-session` / `roadmap update-plan-progress`
  korrumpieren das STATE.md/ROADMAP.md-Frontmatter (überschrieben milestone v5.0.0 → v2.3, droppten
  Spalten). **Für v5.1.0: STATE/ROADMAP manuell editieren oder per plain-git committen; die buggy
  gsd-tools-State/Roadmap-Mutationen MEIDEN.** (Aus v5.0.0 154-01 + Phase-157-Close gelernt.)

### Milestone-Kontext

- Spec: `docs/superpowers/specs/2026-06-30-ghostline-interactive-course-design.md`
- Brainstorm-Verlauf: `.planning/brainstorm-interactive-course.md`
- Engine-Inventur + Schema-Fakten sind im Spec (Sektion 3 + 9) verbatim festgehalten.
- Vorhandene Content-Pools (DB devcloud): Linux 65/66/70/35, History 44, + Netz/Security/A+ für Akt 2–4.

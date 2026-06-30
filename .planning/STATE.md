---
gsd_state_version: 1.0
milestone: v5.1.0
milestone_name: "v5.1.0 Ghostline"
current_phase: 158
current_plan: null
status: "v5.1.0 Ghostline STARTED (2026-06-30). Verbindendes interaktives Story-Universum (Linux→Security→Netzwerk→IT) auf vorhandener Kampagnen-Engine. Spec freigegeben (docs/superpowers/specs/2026-06-30-ghostline-interactive-course-design.md, inkl. verifizierter Schema-Fakten). Akt 1 = LPIC-101 Mini-Slice, deadline-priorisiert (Prüfung 03.07.), K3/Topic-103 Vertical-Slice zuerst. Research-Phase (4 Researcher: Lerndesign/LPIC-Inhalte/Authoring/Pitfalls) gewählt. NÄCHSTER SCHRITT: Research → Requirements → Roadmap."
stopped_at: "new-milestone Flow: PROJECT.md + STATE.md gesetzt, v5.0.0 archiviert (commit 1b20721). Als Nächstes: 4 gsd-project-researcher spawnen, dann Requirements, dann gsd-roadmapper."
last_updated: "2026-06-30"
last_activity: "2026-06-30 — v5.0.0 milestone abgeschlossen + archiviert; v5.1.0 Ghostline gestartet (Brainstorm→Spec→new-milestone). Research-Phase als Nächstes."
progress:
  total_phases: 0
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

Phase: Not started (defining requirements)
Plan: —
Status: Defining requirements (Research-Phase gewählt → 4 Researcher als Nächstes)
Last activity: 2026-06-30 — Milestone v5.1.0 gestartet

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

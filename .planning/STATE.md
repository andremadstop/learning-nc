---
gsd_state_version: 1.0
milestone: v4.0
milestone_name: Housekeeping + Content-Rollout
status: planning
stopped_at: Completed 55-02-PLAN.md (DevCloud cleanup)
last_updated: "2026-03-24T11:33:57.269Z"
last_activity: 2026-03-24 — Roadmap v8.0 created with 3 phases (61-63)
progress:
  total_phases: 32
  completed_phases: 27
  total_plans: 44
  completed_plans: 44
  percent: 0
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-03-24)

**Core value:** Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung.
**Current focus:** Milestone v8.0 — VirtuProf v2

## Current Position

Phase: 0 of 3 (ready to plan Phase 61: Kontext-Mapping)
Plan: —
Status: Roadmap complete, ready to plan
Last activity: 2026-03-24 — Roadmap v8.0 created with 3 phases (61-63)

Progress: [░░░░░░░░░░] 0%

## Performance Metrics

**Velocity:**
- Total plans completed: 0
- Average duration: —
- Total execution time: —

## Accumulated Context

### Decisions

- Ein schlauer Bot mit Kontext-Injection statt Multi-Bot-Swarm (90% Wert bei 10% Komplexitaet)
- Hint-Level pro Frage (nicht global) — reset bei neuer Frage
- Exam-Sperre ueber ExamMode-Kontext (automatisch, nicht manuell)
- Fehler-Reports mit automatischer Fragen-ID (User muss nichts kopieren)
- EXAM + REP in einer Phase kombiniert (beide klein, teilen Kontext-Abhaengigkeit)
- [Phase 55]: admin/Learning/images KEPT — 112 DB references confirmed; memories/ fully deleted; log_rotate_size=50MB

### Existing Architecture

- VirtuProf.vue + VirtuProfBubble.vue + VirtuProfAvatar.vue (Frontend)
- VirtuProfController.php (Chat, State, History, File/Ticket-Intents)
- GeminiService.php (LLM-Backend, System-Prompt, Rate-Limits, Security)
- RagContextService.php (Multi-Source RAG)
- AiChatMemoryService.php (Chat-History)
- SupportTicketService.php (Ticket-System existiert bereits)
- Lernmodi: TrainingMode, LeitnerMode, ExamMode, AbenteuerMode etc.

### Pending Todos

None.

### Blockers/Concerns

- GeminiService max_output_tokens moeglicherweise zu niedrig (VirtuProf Bug: abgeschnittene Nachrichten)

## Session Continuity

Last session: 2026-03-24T11:33:57.253Z
Stopped at: Completed 55-02-PLAN.md (DevCloud cleanup)
Resume file: None

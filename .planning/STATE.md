---
gsd_state_version: 1.0
milestone: v3.2
milestone_name: VirtuProf KI-Assistent
status: executing
stopped_at: Completed 18-01-PLAN.md
last_updated: "2026-03-21T13:37:06.922Z"
last_activity: "2026-03-21 — Phase 17 Plan 02 complete: VirtuProfController chat() endpoint + route registered"
progress:
  total_phases: 21
  completed_phases: 17
  total_plans: 30
  completed_plans: 30
  percent: 100
---

## Current Position

Phase: 18 — RAG-Context
Plan: 01 complete, phase 18 in progress (1 plan done)
Status: In progress (next: Phase 19 Chat-UI)
Progress: [██████████] 100% (historical plans)
Last activity: 2026-03-21 — Phase 18 Plan 01 complete: RagContextService + GeminiService RAG extension + Controller wiring

## Project Reference

**Core Value:** VirtuProf erklaert, beantwortet und unterstuetzt — in der Sprache des Users
**Current Milestone:** v3.2 VirtuProf KI-Assistent (Phases 17-21)
**Current Focus:** Phase 18 — RAG-Context (plan 01 complete, phase done)

## Performance Metrics

- Phases complete (v3.2): 0/5 (Plan 17-01 done, phase 17 in progress)
- Requirements mapped: 24/24
- Phases complete (all time): 16/21

| Phase | Plan | Duration | Tasks | Files |
|-------|------|----------|-------|-------|
| 17 | 01 | 217s | 2/2 | 4 |
| 17 | 02 | 8min | 2/2 | 2 |
| 18 | 01 | 175s | 3/3 | 3 |

## Accumulated Context

### Existing Foundation (built-in, no work needed)
- VirtuProf.vue + VirtuProfBubble.vue + VirtuProfAvatar.vue — ready to extend
- virtuprof-scripts.js mit FAQ-Texten und Trigger-Scripts — FAQ-Fallback vorhanden
- SupportTicketService.php + Admin-Inbox — Triage baut darauf auf
- TranslationService.php + content_language Setting — Mehrsprachigkeit geloest

### Key Technical Decisions
- Gemini Flash 2.5 statt lokalem LLM (GTX 1660 Ti zu schwach, Gemini kostenlos <1000 Req/Tag)
- FAQ-Cache als First Layer (0-Latenz, Fallback bei API-Ausfall)
- Opt-in statt Opt-out (DSGVO-Pflicht bei Drittanbieter-Datenverarbeitung)
- API-Key in Admin-Settings via IConfig (NC-konform, kein .env-Zugang noetig)
- Security MUSS in Phase 17 gebaut werden (nicht nachtraeglich hinzufuegen)
- [17-01] API key stored in IConfig plaintext (NC 29-31 limitation); masked in GET via gemini_api_key_set boolean
- [17-01] Rate-limited requests skip audit log (no-op events add noise without value)
- [17-01] Output blocklist uses deterministic regex, not NLP (predictable, testable)
- [17-02] ai_enabled guard runs before GeminiService.chat() to avoid rate limit counter increments when feature is disabled
- [17-02] HTTP 400 only for invalid_input (client error); all GeminiService fallback outcomes return HTTP 200 with fallback:true

### Architectural Notes
- GeminiService.php: neuer Service, kapselt HTTP-Call + Prompt-Aufbau + Fallback
- RagContextBuilder.php: laedt Pool/Kurs/User-Daten, begrenzt auf 4000 Tokens
- SEC-Layer: Input-Sanitizer → Context-Isolation (<user_message> Tags) → Output-Validation → Rate-Limit → Audit-Log
- PRIV-01 Opt-in: lokaler localStorage-Flag + Vue-Dialog vor erstem API-Call
- PRIV-02 Admin-Toggle: IConfig Key `learning.ai_enabled` (default: false)

### Bekannte Risiken
- Gemini API-Key Management: muss verschluesselt in IConfig, nicht in DB-Plaintext
- Token-Overflow: RAG-Context kann bei grossen Pools den Input-Limit sprengen → Truncation-Logik in RAG-04
- Rate-Limit-Bypass: SEC-04 muss NC ICache nutzen (nicht PHP-Session) fuer Cluster-Sicherheit
- DSGVO: kein Username/E-Mail im LLM-Context — PRIV-04 erfordert explizite Pruefung des Context-Payloads

## Session Continuity

Stopped at: Completed 18-01-PLAN.md
Next action: Run Phase 18 (Frontend VirtuProf chat UI integration)

# Learning-NC

## What This Is

Native Nextcloud App fuer Karteikarten-Lernen mit Leitner-System, Kursen, Arena, KI-Assistent (VirtuProf/NOVA) mit Fullscreen-Modus und Kursende-Reflexion, PBQ-Simulationen, Story-RPG mit Kampagnen, 8 Netzwerk-Simulatoren mit Praxis-Sessions, Kompetenz-Visualisierung (Skill-Map), Kurs-Feed, Student-Dashboard, Kursende-Experience (Zeugnis + Export + KI-Narrative + ICS-Kalender), Klassenbuch und DSGVO-Compliance.

## Core Value

Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung — fuer Einzellerner und Kursgruppen.

## Requirements

### Validated

- ✓ v1.0-v6.0 — Core bis Story-RPG
- ✓ RAG Stufe 2 — v4.1
- ✓ Visual Identity — v6.2
- ✓ Subnetzrechner Pro — v7.2
- ✓ Housekeeping + Content-Rollout — v4.0
- ✓ VirtuProf v2 (Kontext, Hints, Exam-Sperre) — v8.0
- ✓ Simulatoren (7 Tools) — v9.0
- ✓ Campaign Engine Backend — v10.0
- ✓ Telos-Onboarding + VirtuProf Guide — v11.0
- ✓ Campaign Engine (Quest-Map, Simulators, DauBot, Coop) — v12.0
- ✓ DevCloud Optimierung (Pipeline, Talk/Deck, NOVA Personality, Manifest) — v12.1
- ✓ NOVA Visual Redesign, Ghostline Quest, Kurs-Feed, Skill-Map — v13.0
- ✓ UX-Navigation, Simulator-Praxis, Student-Dashboard, DevCloud-Integration — v3.4.0
- ✓ DSGVO (Datenschutz-Seite, AI Consent, Schwarm-Consent, Loeschkonzept) — v3.5.0
- ✓ Kursende-Experience (Summary, Snapshot, Export, Dozenten-Report) — v3.5.0
- ✓ Klassenbuch (Opt-in Grid, vCard, Buddy-Netzwerk) — v3.5.0
- ✓ Security Hardening (Canary Tokens, 19 Injection Patterns, PII-Filter) — v3.5.0
- ✓ Telos Encryption at Rest (AES-256-CBC, bio + telos_json) — v3.6.0
- ✓ Audit-Log Moderation (Schwarm approve/reject protokolliert) — v3.6.0
- ✓ Badge-Umbau (10 aktiv, 25 Legacy, 5 neue Trigger) — v3.6.0
- ✓ Vault-Import (4 CompTIA-Kurse, 2355 RAG-Chunks) — v3.6.0
- ✓ Tab-Reduktion (CourseDetail 3874→759 LOC, 5 Mega-Tabs) — v3.6.0
- ✓ VirtuProf Fullscreen (Top-Level-Tab, X/ESC/Swipe Dismissal) — v3.6.0
- ✓ Narrative Portfolio (Gemini Kursende-Reflexion, Snapshot-Cache) — v3.6.0
- ✓ Forget-Me-Not ICS (Leitner-Wiederholungskalender, Token-basiert) — v3.6.0
- ✓ Privacy-Info 7 DSGVO-Kategorien + PWA-Anleitung — v3.6.0

### Active

**Milestone v3.7.0 — Efficiency & Compliance:**
- [ ] UX-01: Schüler sieht Training-Modus nicht — Dozent behält Zugriff
- [ ] UX-02: Wahr/Falsch als Single-Choice mit 2 Optionen (kein eigener Pool-Typ)
- [ ] UX-03: Migration bestehender Wahr/Falsch-Pools (true_false → single)
- [ ] UX-04: Smart Queue prominent als Lernraum-Einstieg mit fälligen Karten-Count
- [ ] UX-05: mode_config erlaubt Dozenten Modi pro Kurs zu steuern
- [ ] DSGVO-01: NC Help & Privacy zeigt verlinkten DSGVO-Inhalt via config.php privacy URL
- [ ] DSGVO-02: Datenschutzerklärung-Seite mit 7 Kategorien aus privacy-info.json
- [ ] DSGVO-03: Impressum über NC-Settings erreichbar
- [ ] DASH-01: Dozent kann Prüfungsdatum pro Kurs setzen
- [ ] DASH-02: NC Dashboard Widget zeigt Countdown bis Prüfungstermin
- [ ] DASH-03: Widget hidden wenn kein Prüfungsdatum gesetzt

### Out of Scope

- Vue 3 Migration — blockiert durch @nextcloud/vue 9.x
- Kampagnen-Editor GUI — erst nach Engine bewaehrt
- WebSocket-basierter Chat — NC hat keinen WS-Server
- Echtzeit-Duelle — asynchrone Arena funktioniert
- Multi-Provider KI — Fokus auf Gemini-Optimierung
- FSRS statt Leitner — eigener Milestone (v3.8+)

## Context

- **Shipped v3.6.0** (2026-03-30): 4 Phasen (110-113), 9 Plans, 90 Files, +12274/-4564 Zeilen
- v3.6.0: Encryption at Rest, Audit Trail, Badge-Umbau, Vault-Import, Mega-Tab Navigation, VirtuProf Fullscreen, Narrative Portfolio, ICS Kalender-Feed
- 724 Vitest Unit-Tests, 11 PHPUnit ICS-Tests, PHPStan Level 5, ESLint 0 Errors
- 12 User auf learning-dev, 10 kursteilnehmer mit 1 GB Quota
- App Store: v3.4.0 live, v3.6.0 bereit zum Signing
- Multi-KI Workflow: Claude orchestriert, Codex baut Vorarbeit, Gemini liefert Specs + Reviews

## Constraints

- **Vue 2.7**: Kein Vue 3 bis @nextcloud/vue 9.x stabil
- **Nextcloud-Kontext**: Kein WebSocket-Server moeglich
- **Gemini-Abhaengigkeit**: Narrative Portfolio + VirtuProf brauchen Gemini — Fallback bei fehlendem Key
- **Performance**: Graph-Rendering muss auf schwachen Tablets fluessig laufen

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Quest-Map mit D3.js/SVG | Leichtgewichtig, Vue 2 kompatibel | ✓ Good — wiederverwendet fuer Skill-Map |
| Coop via Polling (nicht WebSocket) | NC hat keinen WS-Server | ✓ Good |
| Engine/Renderer Trennung (D3) | Pure Functions testbar | ✓ Good |
| Privacy JSON (nicht t()) | Instanz-spezifisch, Operator-gepflegt | ✓ Good |
| Consent-Version als VARCHAR | Re-Consent via String-Inequality | ✓ Good |
| Snapshot als JSON-Blob | Einfacher Export, komplette Momentaufnahme | ✓ Good |
| Mega-Tab statt Flat-Tabs | 16→5 reduziert kognitive Last | ✓ Good — 80% LOC-Reduktion |
| Narrative via generateNote() | Server-controlled Prompt, kein User-Input | ✓ Good — einmal generiert, dann cached |
| ICS VEVENTs statt RRULE | Einfacher fuer Kalender-Clients | ✓ Good |
| Telos nur bio/telos_json verschluesseln | help_offer/help_wanted braucht SQL fuer Buddy-Match | ✓ Good |

---
*Last updated: 2026-03-30 after v3.7.0 milestone started*

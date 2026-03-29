# Learning-NC

## What This Is

Native Nextcloud App fuer Karteikarten-Lernen mit Leitner-System, Kursen, Arena, KI-Assistent (VirtuProf/NOVA), PBQ-Simulationen, Story-RPG mit Kampagnen, 8 Netzwerk-Simulatoren mit Praxis-Sessions, Kompetenz-Visualisierung (Skill-Map), Kurs-Feed, Student-Dashboard, Kursende-Experience (Zeugnis + Export), Klassenbuch und DSGVO-Compliance.

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

### Active

(Wird in v3.6.0 REQUIREMENTS.md definiert)

### Out of Scope

- Vue 3 Migration — blockiert durch @nextcloud/vue 9.x
- Kampagnen-Editor GUI — erst nach Engine bewaehrt
- WebSocket-basierter Chat — NC hat keinen WS-Server
- Echtzeit-Duelle — asynchrone Arena funktioniert
- Multi-Provider KI — Fokus auf Gemini-Optimierung

## Context

- **Shipped v3.5.0** (2026-03-29): 9 Phasen (101-109), 69 Dateien, +6433/-763 Zeilen
- DSGVO: PrivacyInfo.vue, versionierter AI Consent, Schwarm-Consent, UserDeletedListener (20+ Tabellen)
- Kursende: CourseSummaryService (8 Kategorien), Snapshot-Persistenz, MD/JSON/Print Export, Dozenten-CSV
- Klassenbuch: Opt-in Profil-Grid, vCard-Export, Buddy-Netzwerk
- Security: 6-Layer + Injection Classifier (19 Patterns) + Canary Tokens + PII-Filter
- 646 Vitest Unit-Tests, PHPStan Level 5, 67 Playwright Checks
- 12 User auf learning-dev, Dozent broecker
- App Store: v3.4.0 live, NC-Kompatibilitaet 29-31

## Constraints

- **Vue 2.7**: Kein Vue 3 bis @nextcloud/vue 9.x stabil
- **Nextcloud-Kontext**: Kein WebSocket-Server moeglich
- **Gemini-Abhaengigkeit**: Dynamische Narrative brauchen Gemini — Fallback fuer Offline
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
| Instructor-Tabs flattened | Zwei-Ebenen-Nav war zu komplex fuer 19 Tabs | ✓ Good — visuell mit Separatoren geloest |
| RAG-Chunks anonymisieren statt loeschen | Wissen bleibt fuer Kurs erhalten | ✓ Good |

---
*Last updated: 2026-03-29 after v3.5.0 milestone*

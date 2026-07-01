# Learning-NC

## What This Is

Native Nextcloud App fuer Karteikarten-Lernen mit Leitner-System, Kursen, Arena, KI-Assistent (VirtuProf/NOVA) mit Fullscreen-Modus und Kursende-Reflexion, PBQ-Simulationen, Story-RPG mit Kampagnen, 8 Netzwerk-Simulatoren mit Praxis-Sessions, Kompetenz-Visualisierung (Skill-Map), Kurs-Feed, Student-Dashboard, Kursende-Experience (Zeugnis + Export + KI-Narrative + ICS-Kalender), Klassenbuch und DSGVO-Compliance.

## Core Value

Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung — fuer Einzellerner und Kursgruppen.

## Last Shipped: v5.0.0 Certification-as-a-Service (2026-06-28, tag v5.0.0)

Verifizierbare Zertifizierung als natives Nextcloud-Feature: Pass-Kriterium pro Kurs, signiertes
OB3/VC-Zertifikat (did:web-Issuer), Compliance-Report, öffentliche Verify-Route. 30/30 v1-Requirements.
Archiv: `milestones/v5.0.0-ROADMAP.md` + `v5.0.0-REQUIREMENTS.md`.

## Current Milestone: v5.2.0 „Pflichtschulung" — AWO-Readiness

**Goal:** Pflichtschulungen mit Nachweispflicht sauber abbilden — Video/Material muss vollständig
gesehen sein bevor das Quiz freischaltet, Teamleiter sehen gruppengefilterte Compliance-Reports,
Zertifikate laufen jährlich ab und lösen Re-Zertifizierung aus, und User ohne E-Mail-Konto
funktionieren durchgängig. Ausgelöst vom AWO-Sachsen-Lead (Jan Knizek, Issue #20), der genau
Zertifikate + Reporting + Video-Sperren als Compliance-Blocker für 2000 Mitarbeiter benannte.

**Target features:**
- Video-/Material-Gating: Clip muss komplett gesehen sein, bevor das Quiz freischaltet (NC-gehostete MP4 + Vimeo/YouTube-Embed)
- Teamleiter-RBAC-Reports: Team-Lead-Rolle pro Untergruppe, sieht Compliance-Report nur für die eigene Gruppe
- Re-Zertifizierung: jährliche Ablauffristen + Erinnerung/Re-Enrollment bei Cert-Ablauf (baut auf vorhandenem `expiry_date`)
- Username-Politur: User ohne Mailkonto durchgängig unterstützen + CSV-User-Upload-Helfer

## Paused Milestone: v5.1.0 „Ghostline" (⏸ resume nach LPIC-Prüfung 03.07.)

Interaktives Story-Universum auf der Kampagnen-Engine. Phase 158 (Akt 1 K3 Core) gebaut + auf devcloud
gelistet; nur Andrés Live-Durchspielen (Gate K3-01/K3-04) + Phase 159 offen. Branch
`feature/v5.1.0-ghostline` (nicht gemergt). Handoff: `.planning/HANDOFF-2026-07-01-ghostline-pause.md`.
Requirements archiviert: `milestones/v5.1.0-REQUIREMENTS.md`. Neue teach-then-test-Lehr-Schicht-Spec
(`docs/superpowers/specs/2026-06-30-interactive-lesson-component-design.md`) wartet auf Andrés Review.

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
- ✓ Lehrplan-Timeline + Admin-Export-Werkzeuge (export-pool/course/merge, Archivierung, CSV) — v4.2.0
- ✓ Vue 3 Migration, Vite-Build, Pinia, Vue Router, SSE/Redis/Push — v4.0.0/v3.8.0
- ✓ Character & Personality (Skin-Picker, 3 Archetypen, Animation-Engine, 5-Sprachen i18n) — v4.4.0
- ✓ CySA+ Pool-Konsolidierung + PBQ-Subtypes (inkl. ranking) — v4.4.x
- ✓ Certification-as-a-Service (Pass-Kriterium, OB3/VC-Cert did:web, Compliance-Report, Public-Verify) — v5.0.0

### Active

**Milestone v5.2.0 — Pflichtschulung / AWO-Readiness** (REQ-IDs in REQUIREMENTS.md):
- [ ] Video-/Material-Gating (komplett gesehen → Quiz frei; NC-MP4 + Vimeo/YouTube)
- [ ] Teamleiter-RBAC-Reports (Team-Lead-Rolle pro Gruppe, gruppengefilterter Compliance-Report)
- [ ] Re-Zertifizierung (jährliche Ablauffristen + Erinnerung/Re-Enrollment)
- [ ] Username-Politur (User ohne Mailkonto + CSV-User-Upload-Helfer)

**Paused: Milestone v5.1.0 — Ghostline** (Phase 158 gebaut, resume nach LPIC-Prüfung, siehe Handoff)

### Out of Scope

- Adminer/phpPgAdmin — Sicherheitsrisiko (IDOR, direkte DB-Exposition), NLM-Warnung
- Kampagnen-Editor GUI — erst nach Engine bewaehrt
- Mobile App (Capacitor) — verschoben (war als v5.0 angedacht; v5.0.0 ist jetzt Certification)
- **Eigene Multi-Tenant-Credentialing-Plattform / Org-Issuer-Management** — bewusst NICHT gebaut. Im NC-Rahmen ist der Mandant die Instanz selbst; Tenancy via NC-Gruppen vorhanden. "Andere nutzen es" = sie installieren die App, nicht ich betreibe einen Dienst
- **Voll-eIDAS QEAA via Qualified Trust Service Provider** — externer Vertrag/Kosten, überdimensioniert für App-Schulungszertifikat; did:web-Eigenaussteller reicht
- **Externes Verify-Portal für Vorgesetzte ohne NC-Account** — evtl. späteres Sub-Projekt; v5.0.0 liefert In-App-Verify-Route
- Multi-Provider KI — Fokus auf Gemini 2.5
- Onboarding Redesign — verschoben nach v4.3.0
- Pool Generator (Material→Pool) — verschoben nach v4.3.0

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
| Zertifikat-Format Open Badges 3.0 / W3C VC | 2026-Konvergenzstandard (eIDAS/EUDI-Wallet Dez 2026), wallet-fähig, kein Format-Lock | — Pending (v5.0.0) |
| Aussteller = `did:web` der NC-Instanz | Nur JSON-Datei an Standard-Route, kein Ledger/QTSP; Mandant = Instanz | — Pending (v5.0.0) |
| Signatur Ed25519 statt Eigenbau-Hash | Standardkonform + verifizierbar, gleiche Mechanik wie Hash | — Pending (v5.0.0) |
| KEINE eigene Credentialing-Plattform | Feature der App, nicht SaaS-Dienst; NC-Tenancy reicht (Over-Engineering verworfen) | — Pending (v5.0.0) |

---
*Last updated: 2026-07-01 — v5.2.0 Pflichtschulung gestartet (AWO-Lead), v5.1.0 Ghostline pausiert*

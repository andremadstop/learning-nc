# Requirements: learning-nc — v5.1.0 Ghostline

**Defined:** 2026-06-30
**Core Value:** Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung — hier: Lernen als spannendes interaktives Spiel, damit ein ungeduldiger Lerner die LPIC-1-101-Prüfung besteht.

## v1 Requirements

v1 = **Akt 1 (LPIC-101)**, deadline-priorisiert (Prüfung 03.07.). K3/Topic-103 zuerst (Vertical Slice).
Auf vorhandener Kampagnen-Engine, kein Neubau. Mappt auf Phasen (Nummerierung ab 158).

### Ghostline-Story (STORY)

- [ ] **STORY-01**: Spielbare Akt-1-Kampagne (`ghostline_act1.json`) lädt im Abenteuer-Modus ohne Fehler (Story-Engine validiert Graph)
- [ ] **STORY-02**: VirtuProf erzählt durchgängig im Narrator-/Mystery-Ton (kein Bruch in „Lehr-Modus")
- [ ] **STORY-03**: Nach Kapitel-Abschluss erscheint eine History-„Geist-Erinnerung" (Vignette, Interleaving)
- [ ] **STORY-04**: Akt-1-Ende setzt `state_bag claimed_ghost_box=true` (Homelab-Anker, Persistenz-Hook für spätere Akte)

### K3 Vertical Slice — Topic 103 (K3) — der MVP

- [ ] **K3-01**: K3 ist solo end-to-end spielbar — Spielschleife Story-Intro → Terminal → Auflösung → Inline-Quiz → 2. Terminal (faded) → History → Ende
- [ ] **K3-02**: ≥2 Terminal-Challenges, abgeleitet aus echten Dozenten-grep/sed-Aufgaben
- [ ] **K3-03**: ≥1 Inline-Quiz mit `explanation`; Fragen-Inhalt aus einem Linux-Pool (65/70) eingebettet
- [ ] **K3-04**: Jede Kapitel-Abschluss-Kante hat `conditions.requires_flag` — K3 ist NICHT mit Fehleingaben durchspielbar (Anti-„Chocolate-Broccoli")

### Terminal-Korrektheit (TERM)

- [ ] **TERM-01**: Jede Terminal-Aufgabe akzeptiert die plausiblen Eingabe-Varianten (≥3: ohne/einfache/doppelte Quotes) + hat einen `hint`
- [ ] **TERM-02**: Terminal-Outputs sind auf einer echten Shell erzeugt (Copy-Paste), nicht erfunden

### Content-Korrektheit (CONT)

- [ ] **CONT-01**: Alle Quiz-Inhalte gegen LPIC-1-PDFs / NotebookLM-Lernvault auf Faktenkorrektheit geprüft
- [ ] **CONT-02**: Mindestens die prüfungskritischen 103-Fallen eingebaut (umask, BRE vs ERE, Redirect-Reihenfolge, `sort|uniq`, vi-Modi, Signal-Nummern)

### Retention-Brücke (RET)

- [ ] **RET-01**: Die K3-Befehle existieren parallel als Pool-Cards (FSRS-Retention nach dem Durchspielen) — Pool 65 erweitern oder neuer Ghostline-CLI-Pool

### Material (MAT)

- [ ] **MAT-01**: NotebookLM-Lernfilm/-Audio ist als Kurs-Material („Trainingsband des Geists") verlinkt und erreichbar

### Deploy & Safety (DEPLOY)

- [ ] **DEPLOY-01**: Staged Deploy — zuerst JSON-only unfeatured (Test auf devcloud), FEATURED-Schaltung (AbenteuerMode.vue) erst nach Andrés Freigabe
- [ ] **DEPLOY-02**: Scope-Sentinel — kein PHP/Vue-Edit außer der FEATURED-Zeile; bei JS-Edit Gate 1 grün (ESLint/Vitest)

## v2 Requirements (deferred — nach der Prüfung)

### Weitere Akt-1-Kapitel (CHAP)
- **CHAP-01**: K1 Topic 101 (Systemarchitektur) Kapitel
- **CHAP-02**: K2 Topic 102 (Installation/Paketverwaltung) Kapitel
- **CHAP-03**: K4 Topic 104 (Dateisysteme/FHS) Kapitel

### Weitere Akte (ACT)
- **ACT-02**: Akt 2 Security — bestehende `ghostline_quest.json` als Akt 2 andocken
- **ACT-03**: Akt 3 Netzwerk (Network+/CCNA/Subnetting-Simulatoren)
- **ACT-04**: Akt 4 IT/Cloud (Homelab-Skalierung)
- **ACT-CONT**: state_bag Cross-Act-Kontinuität (Strategie A narrativ / B mergen / C cross-read) — vor Akt-2-Spec entscheiden

### Erweiterungen (EXT)
- **EXT-01**: Video-Embedding-Feature (NotebookLM-Filme inline statt nur verlinkt)
- **EXT-02**: WebVM/echter Sandbox-Terminal („freier Spielplatz")

## Out of Scope

| Feature | Reason |
|---------|--------|
| Server-VM/Cyber-Range (Option ③) | Security/Ops-Last; eigener Milestone, nicht v5.1.0 |
| Multiplayer/Koop in Akt 1 | Solo zuerst (User-Entscheidung); Engine kann's später |
| Neue Fragetypen (Matching, separater Lückentext) | Vorhandene Typen + CLI decken Akt 1 ab |
| PHP/Vue-Feature-Neubau | „85 % existiert" — v5.1.0 ist Content-Authoring, kein Engine-Bau |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| STORY-01 | 158 | Pending |
| STORY-02 | 158 | Pending |
| STORY-03 | 158 | Pending |
| STORY-04 | 158 | Pending |
| K3-01 | 158 | Pending |
| K3-02 | 158 | Pending |
| K3-03 | 158 | Pending |
| K3-04 | 158 | Pending |
| TERM-01 | 158 | Pending |
| TERM-02 | 158 | Pending |
| CONT-01 | 158 | Pending |
| CONT-02 | 158 | Pending |
| RET-01 | 159 | Pending |
| MAT-01 | 159 | Pending |
| DEPLOY-01 | 159 | Pending |
| DEPLOY-02 | 159 | Pending |

**Coverage:** v1 = 16 Requirements, alle gemappt (Phase 158 = 12, Phase 159 = 4). Unmapped: 0 ✓

---
*Requirements defined: 2026-06-30*
*Last updated: 2026-06-30 — v5.1.0 Ghostline, aus Spec + 4-Researcher-Synthese abgeleitet.*

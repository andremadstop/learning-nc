# Requirements: Learning-NC v12.1 DevCloud Optimierung

**Defined:** 2026-03-27
**Core Value:** DevCloud-Infrastruktur automatisieren, strategisch verknuepfen und fuer Studenten + AI-Agents optimieren.

## v12.1 Requirements

### Pipeline

- [x] **PIPE-01**: Sanitize-Script liegt permanent unter `scripts/devcloud-sanitize.py` mit CLI-Argumenten (--track, --dry-run)
- [x] **PIPE-02**: `/lerninhalt` Skill fuehrt nach Inhaltserstellung automatisch Sanitize + Copy nach `_devcloud/` aus
- [x] **PIPE-03**: Staleness-Check erkennt wenn Personal-Vault-Dateien neuer als ihre `_devcloud/`-Kopien sind und warnt

### Talk

- [ ] **TALK-01**: Alle 5 Talk-Raeume haben eine gepinnte Willkommensnachricht mit Zweck und Regeln
- [ ] **TALK-02**: Bei neuen/aktualisierten Lerninhalten wird automatisch ein Post im "Allgemein"-Raum erstellt

### Manifest

- [ ] **MNFT-01**: VirtuProf kann `_manifest.json` lesen und bei Themen-Fragen passende DevCloud-Lerninhalte verlinken
- [ ] **MNFT-02**: Manifest-Empfehlungen unterscheiden zwischen Einsteiger- und Profi-Niveau basierend auf User-Kontext

### Dashboard

- [ ] **DASH-01**: NC-Dashboard zeigt nach Login einen Einstiegslink zum Dozenten-Material und zur Learning App
- [ ] **DASH-02**: Dashboard-Widget oder Default-Files-Redirect zeigt `00-Uebersicht.md` als Startpunkt

### Deck

- [ ] **DECK-01**: Deck-Karten koennen via Beschreibung auf Lerneinheiten in der Learning App verlinken (Deep-Links)
- [ ] **DECK-02**: Beispiel-Karten im Kurs-Kanban enthalten funktionierende Links zu Learning-App-Pools

## Future Requirements

### Erweiterte Integration

- **INTG-01**: VirtuProf erstellt automatisch Deck-Karten basierend auf Lernfortschritt
- **INTG-02**: Bi-direktionale Sync zwischen Deck-Karten und Learning-App Pools
- **INTG-03**: Talk-Bot der automatisch Frage-des-Tages postet

### Multimedia

- **MDIA-01**: Inline-Embeds von Audio/Video in Einsteiger-Markdown-Dateien
- **MDIA-02**: Callout-Embeds fuer Profi-Dateien (aufklappbar)

## Out of Scope

| Feature | Reason |
|---------|--------|
| Talk Video/Audio Calls | Laeuft ueber alfaview, nicht DevCloud |
| Collectives/Wiki | NC-App zu komplex fuer aktuellen Bedarf, Markdown in Files reicht |
| Automatische Uebersetzung N+/S+/L+/C+ | Nur A+ hat Multilang, Rest bleibt de-only fuers Erste |
| Kampagnen-Editor GUI | Eigener Milestone (v13.0+) |
| Telegram-Bot Integration | Eigener Milestone, braucht Architektur-Entscheidung |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| PIPE-01 | Phase 86 | Complete |
| PIPE-02 | Phase 86 | Complete |
| PIPE-03 | Phase 86 | Complete |
| TALK-01 | Phase 87 | Pending |
| TALK-02 | Phase 89 | Pending |
| MNFT-01 | Phase 88 | Pending |
| MNFT-02 | Phase 88 | Pending |
| DASH-01 | Phase 87 | Pending |
| DASH-02 | Phase 87 | Pending |
| DECK-01 | Phase 89 | Pending |
| DECK-02 | Phase 89 | Pending |

**Coverage:**
- v12.1 requirements: 11 total
- Mapped to phases: 11
- Unmapped: 0

---
*Requirements defined: 2026-03-27*
*Last updated: 2026-03-27 after roadmap creation*

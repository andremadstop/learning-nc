# Requirements: Learning-NC Campaign Engine v12.0

**Defined:** 2026-03-25
**Core Value:** Kampagnen die sich wie ein echtes kleines RPG anfuehlen — mit Quest-Map, Simulator-Challenges, Humor und Coop.

## v12.0 Requirements

### Simulator-Integration (SIM)

- [ ] **SIM-01**: User kann in einer Kampagnen-Szene eine eingebettete Simulator-Challenge loesen (Firewall, DNS, Routing, NAT, PortScanner, Wireshark, AuthFlow)
- [ ] **SIM-02**: Simulator-Ergebnis (bestanden/nicht bestanden, Score) wird an die Graph-Engine zurueckgemeldet und setzt pass_flag im State-Bag
- [x] **SIM-03**: Alle 7 Simulator-Komponenten haben saubere Lifecycle-Hooks (beforeDestroy Cleanup) fuer eingebetteten Betrieb
- [x] **SIM-04**: SimulatorShell-Wrapper mappt Kampagnen-Node-Payload automatisch auf die richtige Simulator-Komponente
- [x] **SIM-05**: Kampagnen-Autor kann pro Node ein Simulator-Szenario definieren (type, scenario, pass_flag, optional: scenario_override fuer Inline-Config)

### Quest-Map (MAP)

- [ ] **MAP-01**: User sieht eine 2D-Karte aller Kampagnen-Knoten mit Verbindungen (Edges) als interaktive SVG-Grafik
- [ ] **MAP-02**: Knoten zeigen visuell ihren Status: besucht (gruen), aktuell (pulsierend), erreichbar (hell), gesperrt (grau/Schloss-Icon)
- [ ] **MAP-03**: Edge-Labels sind sichtbar und zeigen die Entscheidungsoption
- [ ] **MAP-04**: Gesperrte Edges zeigen per Tooltip welche Bedingung fehlt (Flag, Item, Reputation)
- [ ] **MAP-05**: User kann durch Klick auf einen erreichbaren Knoten dorthin navigieren
- [ ] **MAP-06**: Quest-Map hat Zoom und Pan fuer groessere Kampagnen

### HUD / Game-UI (HUD)

- [ ] **HUD-01**: Inventory-Panel zeigt gesammelte Items mit Icons und Tooltips
- [ ] **HUD-02**: Reputation-Bars zeigen aktuelle Werte pro Domaene (z.B. Security, Management, Team)
- [ ] **HUD-03**: Timer-Countdown zeigt verbleibende Zeit bei zeitkritischen Szenen visuell an (Fortschrittsbalken + Sekunden)
- [ ] **HUD-04**: Charakter-Info zeigt gewaehlte Rolle, Staerken/Schwaechen, aktuellen Score
- [ ] **HUD-05**: Act-Fortschrittsanzeige zeigt welcher Akt aktiv ist und wie viele es gibt
- [ ] **HUD-06**: Timer-Ablauf hat Konsequenzen (Edge wird gesperrt oder alternative Route erzwungen)

### DauBot-Dialog (BOT)

- [ ] **BOT-01**: User sieht ein Dialog-UI wenn eine Szene ein DauBot-Szenario enthaelt ("Der Azubi hat was kaputt gemacht")
- [ ] **BOT-02**: User waehlt aus Multiple-Choice welchen Fehler der Azubi gemacht hat
- [ ] **BOT-03**: User waehlt aus Multiple-Choice wie der Fehler behoben wird
- [ ] **BOT-04**: Richtige/falsche Antworten beeinflussen Score und Reputation
- [ ] **BOT-05**: DauBot-Szenarien sind humorvoll ueberzeichnet (panische Fehlerbeschreibungen, absurde Ausreden des Azubis)

### Kampagnen-Content (CAMP)

- [ ] **CAMP-01**: Eine vollstaendige Vorzeige-Kampagne "Der grosse Ausfall" mit mindestens 15 Knoten, 3 Akten und 3 verschiedenen Enden
- [ ] **CAMP-02**: Kampagne nutzt mindestens 3 verschiedene Simulator-Typen als Challenges
- [ ] **CAMP-03**: Kampagne hat mindestens 4 humorvolle, ueberzeichnete NPC-Charaktere (z.B. panischer Manager, ueberheblicher Senior-Admin, Azubi "Klaus", gestresste Helpdesk-Kollegin)
- [ ] **CAMP-04**: Kampagne hat Items die gesammelt und eingesetzt werden (z.B. "Server-Logs", "Kaffee fuer den Manager", "Backup-Tape")
- [ ] **CAMP-05**: Kampagne hat Reputation-Verzweigungen (z.B. Security-Reputation hoch → geheimer Pfad)
- [ ] **CAMP-06**: Kampagne hat mindestens 2 Timer-Szenen mit echtem Zeitdruck
- [ ] **CAMP-07**: Campaign JSON hat ein version-Feld und Schema-Validierung beim Laden

### Coop-Multiplayer (COOP)

- [ ] **COOP-01**: Host kann eine Coop-Session starten und erhaelt einen Session-Code
- [ ] **COOP-02**: 1-3 weitere Spieler koennen per Session-Code beitreten
- [ ] **COOP-03**: Alle Spieler sehen dieselbe Szene synchronisiert (Polling-basiert, 2-3s Intervall)
- [ ] **COOP-04**: Bei Entscheidungen (Edge-Wahl) stimmen alle Spieler ab, Mehrheit gewinnt
- [ ] **COOP-05**: Waehrend ein Spieler eine Simulator-Challenge loest, sehen die anderen den Fortschritt
- [ ] **COOP-06**: Host kann die Session beenden, alle Spieler werden benachrichtigt
- [ ] **COOP-07**: Coop-State wird in 3 neuen DB-Tabellen gespeichert (coop_sessions, coop_players, coop_votes)

## v12.1+ Requirements (deferred)

### Editor
- **EDIT-01**: Dozent kann Kampagnen per GUI-Editor erstellen (JSON visuell bearbeiten)
- **EDIT-02**: Kampagnen-Preview im Editor ohne Deploy

### Erweiterungen
- **EXT-01**: Voice-Chat im Coop (WebRTC)
- **EXT-02**: Persistente Coop-Sessions ueber mehrere Tage
- **EXT-03**: Kampagnen-Leaderboard (schnellste Durchlaufzeit, hoechster Score)
- **EXT-04**: Generative Kampagnen (Gemini erzeugt Szenen dynamisch basierend auf Lernprofil)

## Out of Scope

| Feature | Reason |
|---------|--------|
| Kampagnen-GUI-Editor | Erst nach Engine bewaehrt, JSON reicht fuer v12.0 |
| Voice-Chat | Zu komplex, Text-basierte Abstimmung reicht |
| Persistente Coop ueber Tage | Sessions sind ephemer, spart DB-Komplexitaet |
| WebSocket fuer Coop | NC-Infrastruktur hat keinen WS-Server, Polling reicht |
| Mehr als 1 Kampagne | Lieber eine richtig gute als drei mittelmaeige |
| Mobile-optimierte Quest-Map | Desktop-first, responsive in v12.1 |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| SIM-01 | Phase 80 | Pending |
| SIM-02 | Phase 80 | Pending |
| SIM-03 | Phase 80 | Complete |
| SIM-04 | Phase 80 | Complete |
| SIM-05 | Phase 80 | Complete |
| MAP-01 | Phase 81 | Pending |
| MAP-02 | Phase 81 | Pending |
| MAP-03 | Phase 81 | Pending |
| MAP-04 | Phase 81 | Pending |
| MAP-05 | Phase 81 | Pending |
| MAP-06 | Phase 81 | Pending |
| HUD-01 | Phase 82 | Pending |
| HUD-02 | Phase 82 | Pending |
| HUD-03 | Phase 82 | Pending |
| HUD-04 | Phase 82 | Pending |
| HUD-05 | Phase 82 | Pending |
| HUD-06 | Phase 82 | Pending |
| BOT-01 | Phase 82 | Pending |
| BOT-02 | Phase 82 | Pending |
| BOT-03 | Phase 82 | Pending |
| BOT-04 | Phase 82 | Pending |
| BOT-05 | Phase 82 | Pending |
| CAMP-01 | Phase 83 | Pending |
| CAMP-02 | Phase 83 | Pending |
| CAMP-03 | Phase 83 | Pending |
| CAMP-04 | Phase 83 | Pending |
| CAMP-05 | Phase 83 | Pending |
| CAMP-06 | Phase 83 | Pending |
| CAMP-07 | Phase 83 | Pending |
| COOP-07 | Phase 84 | Pending |
| COOP-01 | Phase 85 | Pending |
| COOP-02 | Phase 85 | Pending |
| COOP-03 | Phase 85 | Pending |
| COOP-04 | Phase 85 | Pending |
| COOP-05 | Phase 85 | Pending |
| COOP-06 | Phase 85 | Pending |

**Coverage:**
- v12.0 Requirements: 30 total
- Mapped to phases: 30 (100%)
- Unmapped: 0

---
*Requirements defined: 2026-03-25*
*Last updated: 2026-03-25 — traceability filled by roadmapper*

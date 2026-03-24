# Requirements: Learning-NC v7.2 Subnetzrechner Pro

**Defined:** 2026-03-24
**Core Value:** Interaktives Netzwerk-Lernwerkzeug das schrittweises Lernen, realistische Uebungen und moderne Protokolle (VLAN, IPv6) in einem Browser-Tool vereint.

## v7.2 Requirements

### Toggle-Spalten

- [x] **TOG-01**: User kann im Rechner-Tab einzelne Ergebnis-Zeilen per Checkbox ein/ausblenden (z.B. nur Netzadresse + CIDR sichtbar, Rest verdeckt)
- [x] **TOG-02**: User kann zwischen Presets waehlen (Alle, Anfaenger, Fortgeschritten, Nur Basics) die vordefinierte Spalten-Kombinationen aktivieren
- [x] **TOG-03**: Die Toggle-Einstellung bleibt beim Tab-Wechsel erhalten (Session-persistent)

### Uebungsmodus

- [ ] **UEB-01**: User kann einen Uebungsmodus starten der eine zufaellige Aufgabe aus einem Szenario-Pool stellt
- [ ] **UEB-02**: User gibt seine Antwort(en) in Eingabefelder ein (Netzadresse, Broadcast, CIDR, Host-Anzahl etc.)
- [ ] **UEB-03**: Der Simulator prueft die Antwort automatisch und zeigt Feedback (richtig/falsch + korrekte Loesung)
- [ ] **UEB-04**: User sieht einen Fortschritts-Tracker (X von Y richtig, aktuelle Serie)

### Szenarien-Content

- [ ] **SCN-01**: Mindestens 15 realistische Subnetting-Aufgaben auf CompTIA Network+ Niveau (CIDR-Berechnung, Host-Ranges, nicht-aligned Adressen)
- [ ] **SCN-02**: Mindestens 5 VLSM-Aufgaben (Netzwerk aufteilen fuer mehrere Abteilungen/Standorte)
- [ ] **SCN-03**: Mindestens 5 Praxis-Szenarien mit Kontext (z.B. "Firma mit 3 Standorten", "Server-Rack mit /28")
- [ ] **SCN-04**: Aufgaben haben Schwierigkeitsgrade (Leicht/Mittel/Schwer) und decken typische Fallstricke ab (Broadcast abziehen, Router-Interface, nicht auf Netzgrenze)

### VLAN

- [ ] **VLAN-01**: Neuer Tab "VLAN" im Subnetzrechner mit VLAN-ID Eingabe und Zuordnung zu Subnetzen
- [ ] **VLAN-02**: Visualisierung von Access vs Trunk Ports mit 802.1Q Tagging (welcher Frame bekommt welchen Tag)
- [ ] **VLAN-03**: Inter-VLAN Routing Darstellung (Router-on-a-Stick oder L3-Switch, Subinterfaces mit VLAN-Zuordnung)

### Rechenweg / Erklaer-Modus

- [x] **ERK-01**: Im Binaer-Tab wird unter dem Bit-Grid ein Rechenweg-Panel angezeigt das Schritt fuer Schritt die Berechnung erklaert (Prefix → Host-Bits → Blockgroesse → Maske binär → Broadcast-Formel)
- [x] **ERK-02**: Jedes Ergebnis-Feld im Rechner-Tab hat einen optionalen "Warum?"-Toggle der die Herleitung zeigt (z.B. "Broadcast = Netzadresse OR Wildcard = 192.168.0.0 OR 0.0.0.31 = 192.168.0.31")
- [ ] **ERK-03**: User kann zwischen Kompakt-Ansicht (nur Ergebnisse) und Erklaer-Ansicht (mit Rechenweg) umschalten — Anfaenger sehen Erklaerungen, Profis blenden sie aus

### IPv6

- [x] **IPV6-01**: User kann IPv6-Adressen mit Prefix eingeben und Netzadresse, Host-Range, Typ (Link-Local, Global Unicast, Multicast) berechnen
- [x] **IPV6-02**: Binaer-Display zeigt 128-Bit Darstellung mit farblich markiertem Prefix/Interface-ID
- [ ] **IPV6-03**: Mindestens 5 IPv6-Uebungsszenarien (Prefix-Berechnung, Subnetting eines /48, EUI-64, Link-Local Erkennung)

### Mehrsprachigkeit

- [ ] **I18N-01**: Alle UI-Strings im Subnetzrechner nutzen t('learning', '...') und haben englische Uebersetzungen in l10n/ — kein hardcoded Deutsch
- [ ] **I18N-02**: Fachbegriffe sind korrekt uebersetzt (Netzadresse/Network Address, Subnetzmaske/Subnet Mask, Hostanteil/Host Portion etc.)

## Future Requirements

- **FUT-01**: Persistierung der Uebungsergebnisse in DB (Backend noetig)
- **FUT-02**: Multiplayer/Duell-Subnetting
- **FUT-03**: DHCPv6 vs SLAAC Visualisierung

## Out of Scope

| Feature | Reason |
|---------|--------|
| Backend/PHP-Aenderungen | Anderer Chat, Ops-Ebene |
| DB-Persistierung | Rein Frontend, localStorage reicht |
| Multiplayer/Duell-Subnetting | Eigener Milestone |
| DHCPv6/SLAAC | Zu tief fuer v7.2 |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| TOG-01 | Phase 56 | Complete |
| TOG-02 | Phase 56 | Complete |
| TOG-03 | Phase 56 | Complete |
| IPV6-01 | Phase 57 | Complete |
| IPV6-02 | Phase 57 | Complete |
| UEB-01 | Phase 58 | Pending |
| UEB-02 | Phase 58 | Pending |
| UEB-03 | Phase 58 | Pending |
| UEB-04 | Phase 58 | Pending |
| SCN-01 | Phase 59 | Pending |
| SCN-02 | Phase 59 | Pending |
| SCN-03 | Phase 59 | Pending |
| SCN-04 | Phase 59 | Pending |
| IPV6-03 | Phase 59 | Pending |
| VLAN-01 | Phase 60 | Pending |
| VLAN-02 | Phase 60 | Pending |
| VLAN-03 | Phase 60 | Pending |

**Coverage:**
- v7.2 requirements: 17 total
- Mapped to phases: 17
- Unmapped: 0

---
*Requirements defined: 2026-03-24*
*Last updated: 2026-03-24 after roadmap creation*

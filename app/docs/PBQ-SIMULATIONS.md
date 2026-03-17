# PBQ-Simulationen — Network+ N10-009 (Pool 81)

> Erstellt: 2026-03-17 | Basis: ODT-Notizen zu 6 Original-Simulationsszenarien

---

## Wie PBQ-Fragen funktionieren

PBQ (Performance-Based Questions) sind interaktive Prüfungsaufgaben, die praktisches Wissen testen statt reines Auswendiglernen. In der echten CompTIA-Prüfung laufen sie in einer speziellen Browser-Umgebung.

### Architektur

Die App unterstützt 4 PBQ-Subtypen:

| Subtype | Komponente | Beschreibung |
|---------|-----------|--------------|
| `cli` | `PbqCli.vue` | Terminal-Emulator mit Cisco IOS / Linux / Windows CLI |
| `placement` | `PbqPlacement.vue` | Geräte per Klick auf Netzwerktopologie-Positionen platzieren |
| `dropdown` | `PbqDropdown.vue` | Multiple-Choice-Fragen als Dropdown-Auswahl |
| `cable` | `PbqCable.vue` | Kabelfehler identifizieren (Pin-Mapping) |

### Daten-Flow

```
DB: oc_learning_questions
  ├── question_type = 'pbq'
  ├── pbq_subtype = 'cli' | 'placement' | 'dropdown' | 'cable'
  └── pbq_config = { ... } (JSONB)
         ↓
PbqRenderer.vue  ─── liest pbq_subtype + pbq_config
         ↓
PbqCli.vue / PbqDropdown.vue / PbqPlacement.vue / PbqCable.vue
         ↓
cliStateMachine.js (für CLI: Zustandsautomat mit domain-Schemas)
         ↓
Scoring: evaluation[] Array (CLI) | positions[].correct (Placement) | questions[].correct (Dropdown)
```

### CLI State Machine

Die CLI-Simulation nutzt `app/src/utils/cliStateMachine.js` mit 5 Domains:

- `cisco_ios` — Modi: `exec` (`SW1>`), `config` (`SW1(config)#`), `config-if` (`SW1(config-if)#`)
- `linux` — `user@host:~$ `
- `windows` — `C:\Users\Administrator> `
- `sql` — `mysql> `
- `generic` — `hostname> `

Command-Outputs werden in `pbq_config.command_outputs[terminalName][command]` gespeichert. Matching ist case-insensitive.

---

## Die 6 Simulationsszenarien (Pool 81)

### Szenario 1 — Switch VLAN / LACP Konfiguration
**DB-ID:** 12460 | **Subtype:** `cli` | **Terminals:** SW1, SW2

**Beschreibung:**
Ein Access-Layer-Switch (SW1) wurde ausgetauscht und muss neu konfiguriert werden. PC3 wurde in VLAN 90 (Management) versetzt, der Port ist aber noch nicht konfiguriert. LACP Port-Channel zwischen SW1 und SW2 soll überprüft werden.

**Konfiguration:**
- SW1 Port-Mapping: Gi0/1→PC1 (VLAN 10), Gi0/2→PC2 (VLAN 20), **Gi0/3→PC3 (VLAN 90, FEHLT)**, Gi0/4→Drucker (VLAN 30), Gi0/5→CCTV (VLAN 60)
- LACP: Gi0/7+Gi0/8 als Port-Channel 1 (mode active, 802.3ad)
- SW2: VLAN 90 auf Gi0/2, LACP zu SW1

**Korrekte Lösung:**
```
SW1# conf t
SW1(config)# interface gi0/3
SW1(config-if)# switchport mode access
SW1(config-if)# switchport access vlan 90
SW1(config-if)# end
SW1# show vlan brief         (Verifizierung)
```

**Bewertung (5 Punkte):**
- `switchport access vlan 90` auf SW1 → 3 Punkte
- `show vlan brief` auf SW1 → 1 Punkt
- `show interfaces trunk` auf SW2 → 1 Punkt

**ODT-Quelle:** Key "1" — "Switch 1 Port 3: ein Endgerät im nur VLAN 90 untagged, LACP disabled"

---

### Szenario 2 — MAC-Tabelle und Routing-Diagnose
**DB-ID:** 12465 | **Subtype:** `cli` | **Terminals:** SW1, SW2

**Beschreibung:**
Ein neu eingestellter Netzwerktechniker soll das Netzwerk dokumentieren. Verdacht auf Verbindungsprobleme wegen sich ändernder MAC-Tabellen. Topologie kartieren durch Analyse der MAC-Tabellen und Routing-Infos.

**Korrekte Lösung:**
```
SW1# show mac address-table
SW1# show running-config
SW2# show mac address-table
SW2# show ip route
```

**Wichtige Befunde:**
- SW1 MAC-Tabelle: 7 Einträge, 2 MAC-Adressen auf mehreren Ports (möglicher Loop)
- SW2: Nur 3 Einträge (gesünder), Dateiserver in VLAN 10 auf Gi0/1
- Routing: Default Route 0.0.0.0/0 via 10.0.0.1

**Bewertung (5 Punkte):**
- `show mac address-table` auf SW1 → 2 Punkte
- `show mac address-table` auf SW2 → 2 Punkte
- `show running-config` auf SW1 → 1 Punkt

**ODT-Quelle:** Key "2" — "Commands über Help – show mac address-table, show running-config, show ip route"

---

### Szenario 3 — Router IP-Adressen / Routing-Lücke
**DB-ID:** 12461 | **Subtype:** `cli` | **Terminals:** Router A, Router B, Router C

**Beschreibung:**
Benutzer können nicht auf Dateiserver 2 (10.0.4.x) zugreifen. Routing zwischen Router A, B, C untersuchen.

**Netz-Layout (aus ODT Key "3"):**
```
Router A: Gi1=10.0.5.0/24, Gi2=10.0.6.0/24, Gi3=10.0.0.0/22
Router B: Gi1=10.0.4.0/22 (BC 10.0.7.255), Gi2=10.0.1.0/24, Gi3=10.0.0.0/24
Router C: Gi1=10.0.0.0/22, Gi2=10.0.4.0/22
```

**Korrekte Diagnose:**
- Router A hat **keine Route** zu 10.0.4.0/22 in der Tabelle
- `ping 10.0.4.1` von Router A schlägt fehl
- Router B kennt 10.0.4.0/22 direkt (Gi1)
- Router C hat keine Route zu 10.0.5.0/24 oder 10.0.6.0/24

**Korrekte Lösung:** OSPF-Konfiguration auf Router C erweitern, damit Routes nach 10.0.5.0/24 und 10.0.6.0/24 propagiert werden. Alternativ: statische Route auf Router A.

**Bewertung (6 Punkte):**
- `show ip route` auf Router A → 2 Punkte
- `show ip route` auf Router B → 2 Punkte
- `show ip route` auf Router C → 2 Punkte

**ODT-Quelle:** Key "3" — exakte IP-Adressen für Router A/B/C

---

### Szenario 4 — Netzwerk-Placement (Topologie-Design)
**DB-ID:** 12468 | **Subtype:** `placement`

**Beschreibung:**
Netzwerktopologie für neues Bürogebäude entwerfen. 4 Positionen (A–D) mit den richtigen Geräten belegen. Ein Switch ist bereits gesetzt.

**Positionen und korrekte Geräte:**

| Position | Beschreibung | Korrektes Gerät |
|----------|-------------|----------------|
| Gerät A | Internet-Gateway (direkt unter der Cloud) | **Firewall** |
| Gerät B | LAN-Verbindungsgerät (unter der Firewall) | **Router** |
| Gerät C | Wireless-Gerät im Büro-Bereich (unten links) | **WAP** |
| Gerät D | Wireless-Gerät im Erweiterungsbereich (unten rechts) | **Wireless Range Extender** |

**Logik:**
- Firewall = erste Verteidigungslinie gegen Internet
- Router = Layer-3-Gerät, verbindet Segmente
- WAP = stellt eigenes WLAN-SSID bereit (direkter Client-Zugang)
- Wireless Range Extender = verstärkt/verlängert ein bestehendes WLAN

**Scoring:** `partial` (Teilpunkte pro korrekt platzierten Gerät)

**ODT-Quelle:** Key "4" — "links Switch Mitte ganz oben Firewall (zum Internet), darunter Router, einzeln unten WAP, rechts Wireless range extender ODER unten Wireless range extender und rechts WAP"

---

### Szenario 5 — APIPA / DHCP-Ausfall (Dropdown)
**DB-ID:** 12472 | **Subtype:** `dropdown`

**Beschreibung:**
Mehrere Workstations (PC-A, PC-B, PC-C) haben Adressen im 169.254.x.x/16 Bereich und können nicht auf den Dateiserver (192.168.1.100) zugreifen.

**Fragen und Antworten:**

| Frage | Korrekte Antwort | Erklärung |
|-------|-----------------|-----------|
| Welche IP weist auf Konfigurationsproblem hin? | **169.254.x.x / 16 (APIPA-Bereich)** | APIPA = RFC 3927, Windows/Linux Fallback wenn kein DHCP antwortet |
| Welcher Dienst ist ausgefallen? | **DHCP** | DHCP weist IPs automatisch zu; ohne DHCP → APIPA |
| Wie beheben? | **DHCP-Server neu starten + ipconfig /renew** | Schnellste Lösung; statische IPs wären Workaround |

**ODT-Quelle:** Key "5" — "169.254.x.x/16"

---

### Szenario 6 — WAN-Selektion / Traffic-Analyse (Dropdown)
**DB-ID:** 12464 | **Subtype:** `dropdown`

**Beschreibung:**
Nach Stromausfall Performance-Probleme und VoIP-Störungen. Dashboard zeigt WAN1/WAN2 Metriken, Geräte-Status und Traffic-Tabelle.

**Dashboard-Daten:**
- WAN1: 100 Mbps, 24 ms Latenz, **9.5 ms Jitter**
- WAN2: 50 Mbps, 18 ms Latenz, **2.1 ms Jitter**
- Router A (206.10.1.1): **FEHLER** (offline nach Stromausfall)
- Router B (206.10.1.2): OK
- Workstation 10.1.90.53: **4.820 kb/s** (höchster Traffic)

**Fragen und Antworten:**

| Frage | Korrekte Antwort | Erklärung |
|-------|-----------------|-----------|
| Welches WAN für VoIP? | **WAN2 (50 Mbps, 18 ms, 2.1 ms Jitter)** | VoIP braucht niedrigen Jitter (<30 ms ITU-T G.114); Bandbreite sekundär |
| Welcher Router hat Probleme? | **Router A (206.10.1.1)** | Status FEHLER; 206.x = WAN-Router-IPs |
| Welche Workstation meiste Traffic? | **10.1.90.53 (4.820 kb/s)** | Höchster Wert im 10.1.x.x Bereich; 206.x sind Router, keine Workstations |

**ODT-Quelle:** Key "6" — "WAN2 ist zwar langsamer, hat aber weniger Jitter – damit für VoIP qualifiziert, Router A hat die Verbindungsprobleme, 10.1.90.53 generiert den meisten Traffic unter den Workstations, 206.x sind die Router"

---

## Config-Format Referenz

### CLI-Format

```json
{
  "scenario_image": "data:image/svg+xml;base64,...",
  "domain": "cisco_ios",
  "hint": "Verfügbare Befehle: show vlan brief | conf t | ...",
  "terminals": [
    {
      "name": "SW1",
      "welcome": "Cisco IOS Software, Version 15.2..."
    }
  ],
  "command_outputs": {
    "SW1": {
      "show vlan brief": "VLAN Name ...",
      "conf t": "",
      "interface gi0/1": "",
      "switchport access vlan 10": "% VLAN 10 assigned..."
    }
  },
  "evaluation": [
    {
      "terminal": "SW1",
      "required_pattern": "switchport access vlan 90",
      "points": 3,
      "explanation": "Port muss VLAN 90 zugewiesen werden"
    }
  ]
}
```

**Wichtig für CLI:**
- `domain` muss eines von: `cisco_ios`, `linux`, `windows`, `sql`, `generic`
- `command_outputs` Keys werden case-insensitive gematcht
- Leerer String `""` als Output = Befehl akzeptiert, kein Output (z.B. `conf t`)
- Transitions (mode-Wechsel) sind automatisch: `conf t` → config-Mode, `interface x` → config-if-Mode

### Placement-Format

```json
{
  "scenario_image": "data:image/svg+xml;base64,...",
  "positions": [
    {
      "id": "pos_fw",
      "label": "Internet-Gateway",
      "x_pct": 50,
      "y_pct": 36,
      "correct": "Firewall"
    }
  ],
  "device_options": ["Firewall", "Router", "WAP", "Wireless Range Extender", "Switch"],
  "scoring_mode": "partial"
}
```

**Felder:**
- `x_pct` / `y_pct`: Position als Prozent der SVG-Breite/Höhe (für Hotspot-Overlays)
- `correct`: Muss exakt mit einem Wert in `device_options` übereinstimmen
- `scoring_mode`: `"strict"` (alles oder nichts) oder `"partial"` (Teilpunkte)

### Dropdown-Format

```json
{
  "scenario_image": "data:image/svg+xml;base64,...",
  "questions": [
    {
      "id": "q_voip",
      "label": "Welches WAN-Interface für VoIP?",
      "options": ["WAN1 (100Mbps, 9.5ms Jitter)", "WAN2 (50Mbps, 2.1ms Jitter)"],
      "correct": "WAN2 (50Mbps, 2.1ms Jitter)",
      "explanation": "VoIP braucht niedrigen Jitter. WAN2 hat 2.1ms vs 9.5ms."
    }
  ]
}
```

**Wichtig:** `correct` muss exakt mit einem Wert in `options` übereinstimmen (case-sensitive).

---

## Neue Simulation hinzufügen

### Schritt-für-Schritt

**1. Subtype wählen:**
- Konfigurationsaufgabe mit Befehlen → `cli`
- Geräte in Topologie einordnen → `placement`
- Identifikations-/Analysefragen → `dropdown`
- Kabelfehler diagosntizieren → `cable`

**2. SVG-Diagramm erstellen:**
```python
import base64
svg = """<svg xmlns="http://www.w3.org/2000/svg" width="720" height="400">
  <!-- Topologie-Diagramm -->
</svg>"""
b64 = "data:image/svg+xml;base64," + base64.b64encode(svg.encode()).decode()
```

**3. Config-JSON aufbauen** (Format siehe oben je Subtype)

**4. SQL-Update schreiben:**
```sql
INSERT INTO oc_learning_questions (
  pool_id, question_type, text, explanation, pbq_subtype, pbq_config, lang, points
) VALUES (
  81, 'pbq',
  'Szenario-Beschreibung...',
  'Lösung: ...',
  'cli',
  '{"domain":"cisco_ios","terminals":[...]}'::jsonb,
  'de',
  5
);
```

**5. Ausführen:**
```bash
ssh learning-dev 'docker exec -i learning-db psql -U oc_admin -d nextcloud' < update.sql
```

**6. Verifizieren:**
```bash
ssh learning-dev 'docker exec learning-db psql -U oc_admin -d nextcloud -c \
  "SELECT id, pbq_subtype, LEFT(text,60) FROM oc_learning_questions WHERE pool_id=81 AND question_type='"'"'pbq'"'"' ORDER BY id;"'
```

---

## DB-Struktur

```sql
-- Pool 81 PBQ-Fragen nach dem Update:
-- ID     | Subtype    | Szenario
-- 12460  | cli        | S1: Switch VLAN/LACP (SW1+SW2)
-- 12461  | cli        | S3: Router A/B/C Routing-Diagnose
-- 12464  | dropdown   | S6: WAN-Selektion / Traffic-Analyse
-- 12465  | cli        | S2: MAC-Tabelle / Routing-Diagnose
-- 12468  | placement  | S4: Netzwerk-Topologie-Design
-- 12472  | dropdown   | S5: APIPA / DHCP-Ausfall
```

---

## Qualitätshinweise

- **CLI-Outputs**: Echtes Cisco IOS Format (Einrückung, Spaltenbreiten, Codes-Zeile)
- **IP-Adressen**: Aus ODT-Quelle exakt übernommen (Router A/B/C Netze)
- **Deutsche Texte**: Alle Fragen/Erklärungen auf Deutsch
- **SVG-Diagramme**: 720px breit, dark theme für CLI, light theme für Placement
- **Erklärungen**: Zeigen nach dem Abschicken die vollständige Lösung mit Begründung
- **Prüfungsnähe**: Szenarien 1–6 basieren auf dokumentierten echten N10-009 PBQ-Themen (ODT-Notizen)

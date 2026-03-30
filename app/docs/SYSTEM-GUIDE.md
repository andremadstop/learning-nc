# Wie ein Nicht-Programmierer mit KI ein professionelles IT-System gebaut hat

> Ein technisches Briefing — und eine Einladung zum Nachbauen.

## Ausgangslage

Anfang 2026. Kein Informatik-Studium, keine Programmiererfahrung, kein Unternehmen. Ein Proxmox-Server, Neugier und Claude Code.

Heute: 17 VMs/Container, 16+ KI-Agents, eine App im Nextcloud App Store, 4 Telegram-Bots fuer die Familie, ein Self-Improvement-Loop der aus jeder Session lernt, und eine Infrastruktur die ein Senior DevOps als "solide" bezeichnen wuerde.

---

## 1. Infrastruktur-Ueberblick

### Was laeuft

- **Proxmox VE** Hypervisor mit 17 VMs/Containern (NixOS, Debian, Alpine)
- **Workstation** (Arch-basiert, GPU fuer lokale LLMs)
- **Relay VPS** (oeffentliche Services via WireGuard-Tunnel)
- 3 Hosts auf **NixOS** mit deklarativen, versionierten Configs — jede Aenderung ist ein Git-Commit, Rollback jederzeit moeglich

### Services (Auswahl)

Nextcloud, Home Assistant, n8n (Workflow-Automation), Paperless-NGX (Dokumenten-OCR), Vaultwarden (Passwort-Manager), AdGuard (DNS-Filter), Jellyfin (Media), Audiobookshelf, plus On-Demand Game-Server.

### Architektur-Prinzip

Alles self-hosted. Kein Cloud-Vendor-Lock-in. Jeder Service laeuft in einem eigenen Container oder einer eigenen VM mit klarer Netzwerk-Segmentierung.

---

## 2. KI-Agenten-Architektur

### Tier 1: Entwicklung & Architektur

| Agent | Aufgabe |
|-------|---------|
| **Claude Code** | Architektur, Code-Review, Security, Koordination |
| **Codex** | Bulk-Implementierung, Tests, parallele Ausfuehrung |
| **Gemini** | Analyse, Transkription, Content-Verarbeitung |

Arbeitsteilung: Claude plant und reviewt, Codex implementiert autonom (via formalisierte Handoff-Dokumente), Gemini analysiert Medien.

### Tier 2: Familien-Bot-Plattform

Eine Python-Codebasis, 4 Container, 4 personalisierte Telegram-Bots mit eigenen Personas und Tool-Sets. Jeder User bekommt einen individuell zugeschnittenen Assistenten.

**Technischer Stack:**
- LLM: Gemini 2.5 Flash (Primary) + Ollama (Fallback, lokal)
- Memory: SQLite (Konversationen, Learnings, Preferences)
- RAG: ChromaDB + lokale Embeddings
- Voice: Gemini TTS + edge-tts Fallback
- Hosting: Podman rootless auf NixOS
- Content Pipeline: Video-URL → Download → Transkription → Zusammenfassung → Vault

### Tier 3: Automatisierung

| Agent | Funktion |
|-------|----------|
| Voice Pipeline | Sprachnachrichten-Transkription (n8n + Gemini STT) |
| ScanInbox | Scanner → OCR Pipeline |
| KlimaBot | Smart-Home Automationen (Schimmelwarnung, Lueftung) |
| Healthcheck | SSH-Check aller Services, Telegram-Alert bei Ausfall |

### Tier 4: Lokal (Offline-faehig)

- **Ollama** mit 10+ Modellen (GPU-beschleunigt)
- **Whisper ASR** (lokale Spracherkennung)
- **Stable Diffusion** (Bildgenerierung)

---

## 3. Software-Entwicklung: learning-nc

### Was es ist

Eine **Nextcloud App fuer Karteikarten-Lernen** mit Spaced Repetition (Leitner-System). Im offiziellen Nextcloud App Store veroeffentlicht.

### Tech Stack

- Backend: PHP 8.1, Nextcloud App Framework, PostgreSQL 16
- Frontend: Vue 2.7, Webpack
- Hosting: Docker auf eigenem Dev-Server

### Feature-Umfang (v12.0)

**Kern-Features:**
- **Leitner-System** mit 5 Boxen + Smart Queue
- **Exam Mode** mit Timer, Attempts, Deadlines
- **PBQ-Simulator** (Performance-Based Questions): CLI State Machine, SVG Topology, Drag and Drop

**Arena & Multiplayer:**
- Live-Duell, Gameshow-Modi (Sprint, Elimination), Brettspiel-Modi
- Liga-System mit Saisons und Leaderboard
- Coop-Modus (2-4 Spieler, Kampagnen gemeinsam loesen)

**Kampagnen-RPG:**
- Graph-basiertes Erzaehlsystem mit Entscheidungen und Konsequenzen
- 3 spielbare Kampagnen (Security+ Szenarien mit NPCs, Items, Reputation)
- Quest-Map (D3.js), HUD, Timer-Countdown, DauBot (KI-Azubi als Lern-Mechanik)

**8 Netzwerk-Simulatoren:**
- DNS-Resolver, Firewall-Builder, Port-Scanner, Routing-Tabelle, NAT-Tabelle, Wireshark-Lite, 802.1X Auth-Flow, Subnetzrechner Pro

**KI-Assistent VirtuProf:**
- Kontext-bewusster Tutor mit Hint-System
- Telos-Onboarding (Lernprofil erstellen)
- TTS/STT Voice-Settings (15 Sprachen)

**Zahlen:** 51+ API Endpoints, 13+ DB-Tabellen, 15 Services, 20+ Vue Components, 2000+ Pruefungsfragen (DE+EN)

### Entwicklungsprozess

Alles mit KI gebaut. Keine Zeile PHP oder Vue vorher geschrieben.

**Quality Gates (4-Gate Pyramide):**
1. **Statisch**: PHPStan Level 5, ESLint 0 Errors, 471 Unit-Tests, Security Scan
2. **API**: 25+ automatisierte Endpoint-Tests
3. **Browser**: 67 Playwright E2E Checks
4. **Release**: PHPUnit + manuelles Testprotokoll (62 Checks)

Pre-Push Hook blockiert Code der Gate 1 nicht besteht. Impact-Analyse via Knowledge Graph vor jeder Aenderung.

---

## 4. Sicherheitskonzepte

### Prinzipien

- **Secrets nur in Passwort-Manager oder .env** — nie in Code, Logs oder Markdown
- **SSH mit dedizierten Keys** pro Zweck (Arbeit, Mounts, Notfall) — IdentitiesOnly verhindert Key-Leakage
- **DNS-Filtering** + VPN-Tunnel + Reverse Proxy mit SSL fuer alle Services
- **Firewall** nur LAN + VPN Zugriff auf Verwaltungs-Interfaces
- **Backups** dedupliziert + verschluesselt (restic), automatisiert mit Alerting

### Application Security

- Rate Limiting, CSRF (Framework), Prepared Statements (kein SQL Injection)
- Access Control: Ownership + Share-Checks in jedem Service
- Optimistic Locking gegen Race Conditions
- 3 externe Security Reviews

### Privacy

- Kinder-Daten mit TTL, redaktierte Logs
- Verschluesselte persoenliche Daten (AES-256-GCM)
- Kein Cloud-Upload — alles self-hosted

---

## 5. Wissensmanagement

### Obsidian als zentrale Wissensbasis

- Hunderte Markdown-Dateien in einem Vault
- Echtzeit-Sync zwischen allen Geraeten (CouchDB + LiveSync)
- Glossar, Rezepte, Lebensmittel-Lexikon — alles strukturiert mit Frontmatter
- Familienmitglieder haben eigene Vaults, Bots schreiben automatisch rein

### Lernpfad

CompTIA Network+ → Security+ → CySA+ → Linux+ — das eigene Homelab als Praxislabor. Eigene Pruefungsfragen-Pools in der App (2000+ Fragen).

---

## 6. Selbst-verbessendes System

### ISC-Workflow (Ideal State Criteria)

Vor jeder nicht-trivialen Aufgabe:
1. **OBSERVE** — Ziel in binaere, testbare Kriterien uebersetzen
2. **BUILD** — Umsetzen
3. **VERIFY** — Jeden Punkt explizit abhaken
4. **LEARN** — Rating + Signal

### Signals → Steering Rules

Jede Session wird bewertet (Rating 1-10). Bei genuegend Eintraegen werden Muster zu konkreten Regeln synthetisiert die in zukuenftige Sessions einfliessen. Das System wird mit jeder Nutzung klueger.

### Formalisiertes Projekt-Management

Roadmap → Milestones → Phases → Plans → Tasks. Spezialisierte Sub-Agents (Researcher, Planner, Executor, Verifier). Atomic Commits, State Tracking, Deviation Handling.

---

## 7. Was KI hier wirklich geleistet hat

### Ersetzt

- Backend-Entwickler, Frontend-Entwickler, DevOps-Engineer
- Security Auditor, Technical Writer, Sys-Admin

### Nicht ersetzt

- **Architektur-Entscheidungen** — KI schlaegt vor, Mensch entscheidet
- **Produkt-Vision** — Was gebaut wird kommt vom User
- **Qualitaetsurteil** — "Ist das gut genug?" ist menschlich
- **Risiko-Bewertung** — Destruktive Aktionen brauchen Freigabe

### Der echte Hebel

Nicht "KI schreibt Code". Sondern: **KI multipliziert die Lerngeschwindigkeit.**

Jedes geloeste Problem wird zu Wissen. Jedes Pattern wird zu einer Regel. Jede Regel macht die naechste Session besser. Nach 50+ Sessions ist das System klueger als jede einzelne Session es sein koennte.

Das ist kein Vibe Coding. Das ist akkumuliertes, strukturiertes, nachpruefbares Systemwissen.

---

## 8. Zum Nachbauen

### Was du brauchst

- Einen Rechner (Linux, Windows mit WSL, oder Mac)
- Einen Claude Code Account (oder anderes KI-Coding-Tool)
- Obsidian (kostenlos) fuer Wissensmanagement
- Neugier und die Bereitschaft, Fehler als Lernchance zu sehen

### Der Kern in 3 Saetzen

1. **Obsidian Vault als Gedaechtnis** — Alles was du lernst, baust, entscheidest wird in Markdown festgehalten. Das ist dein persistentes Wissen das ueber Sessions hinweg waechst.
2. **KI als Multiplikator** — Du gibst die Richtung vor, KI implementiert. Aber: Du musst die Ergebnisse verstehen und pruefen koennen. "Vertrauen aber verifizieren."
3. **Feedback-Loop** — Jede Session macht dich besser. Signals, Korrekturen, Patterns werden gespeichert und fliessen in die naechste Session ein. Nach 50 Sessions bist du 10x schneller als am Anfang.

### Erste Schritte

1. **Obsidian installieren** — Einen Vault anlegen, erste Notizen schreiben (was willst du bauen? was kannst du schon?)
2. **Claude Code einrichten** — CLAUDE.md schreiben (wer bist du, was sind die Regeln, wo liegt der Code)
3. **Kleines Projekt starten** — Nicht mit "ich baue eine App" anfangen, sondern mit "ich lerne wie ein API-Endpoint funktioniert"
4. **Jeden Tag 30 Minuten** — Konsistenz schlaegt Intensitaet. Lieber taeglich 30 Minuten als einmal pro Woche 8 Stunden.

### Was dieses System NICHT ist

- Kein Ersatz fuer Grundlagen-Verstaendnis (du musst wissen WAS du baust, auch wenn KI das WIE uebernimmt)
- Kein Magic Button (es braucht Wochen bis der Feedback-Loop greift)
- Kein Solo-Projekt (Community, Mentoren und Peers sind unverzichtbar)

---

*Stand: 2026-03-26 | Erstellt mit Claude Code, verifiziert vom Autor.*

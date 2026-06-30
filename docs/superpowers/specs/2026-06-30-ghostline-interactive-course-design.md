# Spec — „Ghostline": Interaktives Cross-Domain-Lernuniversum

> Status: DESIGN (Brainstorm abgeschlossen, vom User freigegeben 2026-06-30)
> Begleitnotizen / Brainstorm-Verlauf: `.planning/brainstorm-interactive-course.md`
> Treiber: André bereitet die **LPIC-1-101-Prüfung (03.07.2026)** vor, lernt aber nur, wenn es
> Spaß macht und spannend ist. Ziel: bestehen — über ein Lernerlebnis, kein trockenes Theoriepauken.

---

## 1. Vision

Ein **EIN großes verbindendes Story-Universum** in der bestehenden Nextcloud-Lern-App
(`learning-nc`), das Andrés IT-Lernweg der letzten Monate zusammenführt — **Linux, Security,
Netzwerk, allgemeine IT** — modular in Akte gegliedert, mit echtem Terminal-Üben, KI-Erzähler,
History- und Homelab-Bezug, Schwierigkeitsstufen und Gamification.

**Zentrale Erkenntnis (Code-Scan 2026-06-30):** ~85 % der gewünschten Bausteine **existieren
bereits** in der App. Das Projekt ist daher primär **Content-Authoring auf vorhandener Engine**
plus wenige gezielte Lücken — **kein** Neubau. Bild: André besitzt ein komplettes Studio und hat
Drehbücher (26+ Kampagnen) geschrieben, aber nie einen Film gedreht. Wir drehen den ersten Film.

## 2. Das Universum — „Ghostline" (Hybrid ①+②+③)

- **Spine ① Ghostline (Noir-Mystery):** Der Spieler erbt den Zugang zu den Systemen eines
  verschwundenen Admins („der Geist"). Ein Geheimnis entfaltet sich Schicht für Schicht über die
  IT-Domänen. Mystery = Neugier-Sog → trägt den „keine-Geduld"-Lerner.
- **Erdung ② Homelab:** Der Geist hatte ein **Homelab wie Andrés echtes** (Proxmox / Tailnet /
  Nextcloud). Der Spieler übernimmt es; es **bleibt seins** und wächst über die Akte
  (persistenter `state_bag`). „Das gehört mir"-Gefühl + Praxisbezug.
- **History-Würze ③ The Long Wire:** Der Geist hinterließ ein **verschlüsseltes Journal**. Jeder
  freigeschaltete Eintrag = **„Geist-Erinnerung"** = kurze History-Vignette
  (Bell Labs → Shell, ARPANET → Netz, Morris-Wurm → Security), gewürzt mit Quiz aus dem
  Computergeschichte-Pool.
- **Erzähler:** VirtuProf im **Story-Narrator-Mode** (Gemini 2.5 Flash, existiert).

### Akt-Roadmap (modular)
| Akt | Domäne | Inhalt | Status |
|-----|--------|--------|--------|
| **1** | **Linux / LPIC-101** | Shell erben, totes System wecken, Journal entschlüsseln | **Mini-Slice (dieses Spec, jetzt)** |
| 2 | Security | CompTIA Security+ / Brücken-Material; der „Einbruch" | Konzept (späteres Milestone) |
| 3 | Netzwerk | Network+ / CCNA / Subnetting; das „kompromittierte Netz" | Konzept |
| 4 | IT / Cloud | Homelab-Skalierung, Azure/MS-Server; Auflösung | Konzept |

> Dieses Spec **designt das Universum auf Skelett-Ebene** und **spezifiziert Akt 1 vollständig**.
> Akt 2–4 bekommen je ein eigenes Spec → Plan → Bau, wenn es soweit ist.

## 3. Architektur — Mapping auf vorhandene Engine

Kein neuer Code-Pfad nötig; Akt 1 nutzt ausschließlich existierende Bausteine:

| Bedarf | Vorhandener Baustein | Pfad |
|--------|----------------------|------|
| Story-Graph (Akte, Szenen, Entscheidungen) | Campaign-JSON + Story-Engine | `data/campaigns/*.json`, `lib/Service/StoryEngineService.php` |
| Kampagnen-Auswahl & Spielfluss | Abenteuer-Modus | `src/components/.../AbenteuerMode.vue` |
| Echtes Terminal-Üben (scripted) | PBQ-CLI (Linux-Schema) | `src/components/.../PbqCli.vue`, `cliStateMachine.js` |
| Skill-Checks (echte Fragen) | Pool → Question, Story-Skill-Check | `oc_learning_pools`, `StoryEngineService` Skill-Check-Nodes |
| KI-Erzähler / Dialog | VirtuProf Narrator-Mode | `GeminiService.php`, `VirtuProf.vue` |
| Fortschritt/Persistenz | Story-Progress + `state_bag` | `oc_learning_story_progress`, `oc_learning_campaign_state` |
| Charakter-Klassen | architect/security/sysadmin/helpdesk | Campaign-JSON `.character_classes` |
| Schwierigkeitsstufen | Campaign-Difficulty-Tags + FSRS-Difficulty + Bot-Difficulty | vorhanden |
| Material (Filme/Audios) | Kurs-Dokumente / Material-Links | `oc_learning_course_documents` |

**Bewusst NICHT im Scope (kommt in der großen Welt, nicht im Mini-Slice):**
Video-Embedding-Feature (❌ existiert nicht; NotebookLM-Filme nur verlinkt), WebVM/Server-VM-
Sandbox (②/③ Terminal-Stufen), Multiplayer/Koop, Matching-/separater-Lückentext-Fragetyp.

## 4. Akt 1 — „Ghostline: First Contact" (LPIC-101)

**Frame:** Der Spieler erbt Zugang zur toten Box des Geists. VirtuProf erzählt/führt. Ziel:
System wiederbeleben **und** das Journal entschlüsseln. 4 Kapitel = die 4 LPIC-101-Themen.

| Kap | LPIC-Thema (Gewicht) | Spieler-Handlung | Bausteine | Content-Quelle |
|-----|----------------------|------------------|-----------|----------------|
| K1 | 101 Systemarchitektur | Box bootet nicht sauber → diagnostizieren (lspci/dmesg, Runlevel/systemd-Targets) | Story + Skill-Check | Pool 65 (Linux Admin 1 MCQ), 35 |
| K2 | 102 Installation/Paketverwaltung | Fehlende Tools nachinstallieren (apt/dpkg/rpm), Partitionen/LVM/GRUB | Story + Skill-Check | Pool 65 |
| **K3** | **103 GNU/Unix-Befehle (26/60 Pkt)** | **Zerstückeltes Journal per grep/sed/sort/pipe/vi rekonstruieren** | **PbqCli-Terminal** + Skill-Check | **Dozenten-grep/sed-Aufgaben** + Pool 65 |
| K4 | 104 Dateisysteme/FHS | Verstecktes Volume mounten, Rechte fixen (chmod/SUID), Links folgen → finaler Eintrag | Story + Skill-Check | Pool 65, 70 (Premium-Szenarien) |

- Jeder Kapitel-Abschluss schaltet eine **Geist-Erinnerung** frei (History-Vignette als Story-Node;
  optionales Mini-Quiz aus **Pool 44 „Computergeschichte"**).
- **NotebookLM-Filme/Audios** des bestehenden „LPIC-1 Lernvault"
  (Notebook-ID `3bf916d0-7984-4c58-a542-a3277698263c`) als **„Trainingsbänder des Geists"** verlinkt.
- **Akt-1-Ende:** Spieler „beansprucht" die Box → `state_bag`-Flag `claimed_ghost_box=true`,
  persistiert in Akt 2–4 (Homelab-Erdung).

### Content-Bausteine (Einheiten, je isoliert verständlich/testbar)
1. **Campaign-Datei** `data/campaigns/ghostline_act1.json` — Story-Graph (Nodes/Edges/Acts,
   character_classes, dynamic_choices, Skill-Check-Tags, Material-Links).
2. **CLI-Szenarien** für K3 — 2–3 PbqCli-Terminal-Aufgaben, abgeleitet aus echten
   Dozenten-`Aufgabe_grep_*.txt` (Aufgabenstellung + erwartete Lösungs-Befehle bereits vorhanden).
3. **Pool-Verdrahtung** — Skill-Check-Nodes referenzieren bestehende Pools (65/70/35/44),
   kein neuer Pool-Import zwingend für MVP. (Optionales LPIC-Objective-Tagging später.)
4. **History-Journal-Mechanik** — Story-Nodes „Geist-Erinnerung", inhaltlich aus Computer-
   geschichte + Unix-Historie.
5. **Material-Verknüpfung** — NotebookLM-Artefakte als Kurs-Dokument-Links.

### Datenfluss (Spielschleife K3)
Story-Intro-Node (VirtuProf erzählt) → `simulator`/`terminal`-Node (PbqCli wertet Befehl gegen
erwartete Lösung) → bei Erfolg `skill-check`-Node (Frage aus Pool 65) → `story`-Node
„Journal-Fragment entschlüsselt" → `story`-Node „Geist-Erinnerung" (History) → Edge zum nächsten
Kapitel / Akt-1-Ende. Fortschritt in `oc_learning_story_progress`, Flags in `state_bag`.

### Schwierigkeitsstufen
Akt-1-MVP nutzt vorhandene Difficulty-Mechanik: Skill-Check-Fragen über Pool-Difficulty/FSRS;
optionaler „Hardcore"-Pfad (mehr Terminal, weniger Multiple-Choice) via Campaign-Difficulty-Tag.
Voll ausgebaute Stufen = große Welt.

## 5. MVP & Schnitt (harte 3-Tage-Uhr bis 03.07.)

**Vertical Slice zuerst — nur K3 (Topic 103) end-to-end spielbar:**
Story-Intro → 2–3 Terminal-Challenges (aus echten grep-Aufgaben) → Skill-Check (Pool 65) →
Journal-Fragment → Geist-Erinnerung (Unix-History) → Cliffhanger.
*Begründung:* 103 ist mit 26/60 Punkten fast die halbe Prüfung → höchster Lernwert; und der Slice
beweist die komplette Schleife auf der Engine.

**Danach (Tag 2–3, soweit Zeit):** K1/K2/K4 anhängen; André spielt es als echtes Lernen.

## 6. Erfolgskriterien (binär, testbar)
1. [ ] `ghostline_act1.json` lädt im Abenteuer-Modus ohne Fehler (Story-Engine validiert Graph).
2. [ ] K3 ist solo end-to-end spielbar: Intro → ≥2 PbqCli-Terminals → Skill-Check → Journal → Ende.
3. [ ] Mindestens 2 PbqCli-Terminal-Aufgaben stammen aus echten Dozenten-Aufgaben und werten
       korrekte Eingaben als bestanden / falsche als nicht bestanden.
4. [ ] Mindestens 1 Skill-Check zieht echte Fragen aus einem bestehenden Linux-Pool (65/70/35).
5. [ ] Mindestens 1 „Geist-Erinnerung" (History-Vignette) wird nach Kapitel-Abschluss angezeigt.
6. [ ] NotebookLM-Film/Audio ist als Material verlinkt und erreichbar.
7. [ ] `state_bag claimed_ghost_box` wird am Akt-1-Ende gesetzt (Persistenz-Hook für spätere Akte).
8. [ ] Quality Gate 1 grün (PHPStan/ESLint/Vitest), soweit Code berührt; reines Content-JSON
       durchläuft Story-Engine-Validierung.

## 7. Risiken & offene Punkte
- **Campaign-JSON-Schema-Ergonomie:** Wie aufwändig ist Authoring eines neuen Graphen? Muss im
  Implementierungsplan an einer bestehenden Kampagne (z. B. `ghostline_quest.json`) verifiziert
  werden, bevor K1/K2/K4 geplant werden.
- **PbqCli-Szenario-Format:** Schema der Linux-CLI-Szenarien (Erwartungs-Matching) prüfen — passt
  das grep-Aufgaben-Format direkt? (Implementierungsplan-Frage.)
- **Pool-Eignung:** „Linux Admin 1"-Pools sind Linux-fundamental, aber nicht LPIC-objective-getaggt.
  Für MVP ausreichend; präzises 101.x-Tagging ist spätere Kür.
- **Deploy auf Live-Devcloud:** Es lernen echte Studenten dort. Akt 1 zunächst als nicht-
  prominente/Test-Kampagne einspielen, bis André sie freigibt.
- **Zeit:** Voller 4-Kapitel-Akt in 3 Tagen neben echtem Lernen ist knapp → Vertical Slice ist Pflicht-MVP.

## 8. Nicht im Scope (bewusst, YAGNI)
Video-Embedding-Feature, WebVM/Server-VM-Terminal (②/③), Multiplayer/Koop in Akt 1, neue
Fragetypen (Matching/separater Lückentext), Akt 2–4-Inhalte, LPIC-Objective-Feintagging der Pools.

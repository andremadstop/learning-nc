# Übergabeprotokoll & Projektsteuerung: "Learning" App

**An:** Projekt-Team (Claude, weitere KI-Agenten)
**Von:** Visionär & Supervisor
**Datum:** 11.02.2026
**Version:** 1.1

Dieses Dokument ist die zentrale Quelle der Wahrheit (Single Source of Truth) für das Nextcloud "Learning"-App-Projekt. Alle Aktionen und strategischen Entscheidungen sind an den hier festgehaltenen Prinzipien auszurichten.

> **Ops-Framework-Referenz:** Agenten-Rollen und Trigger folgen dem [Personal Ops OS Framework](../personalAssistant/.claude/projects/-home-andre-AIWorkspace-personalAssistant/memory/ops-framework.md). Dieses Dokument definiert keine eigenen Rollen, sondern mappt auf das bestehende System.

---

## 1. Vision & Executive Summary

**Projekt:** "Learning" – eine native, tief in Nextcloud integrierte Lernplattform, die auf dem Spaced-Repetition-System (Leitner) basiert.

**Vision:** Wir erschaffen kein kommerzielles Produkt, sondern ein außergewöhnlich erfolgreiches, weit verbreitetes und langlebiges Open-Source-Projekt. Unser Motto lautet: **"Von einer App zu einem Ökosystem."** Der Erfolg wird nicht in Geld, sondern in Nutzerakzeptanz, Community-Beteiligung und praktischem Nutzen gemessen.

---

## 2. Projekthistorie & Aktueller Stand

Das Projekt begann mit dem Ziel, eine bestehende Web-App zu portieren. Eine Analyse auf dem Entwicklungsserver (`192.168.178.65`) hat ergeben, dass die Entwicklung bereits weit fortgeschritten ist.

**Aktueller Stand:**
*   **Source Code:** Der primäre Code befindet sich auf dem Dev-Server (CT 201, 192.168.178.65) unter `/home/andre/learning-nc/app`. Das lokale Repo (`~/AIWorkspace/learning-nc/`) enthält Infra-Config (Docker-Compose, Docs, Roadmap) — der App-Code lebt ausschließlich auf der VM. Code-Sync ins Repo ist ausstehend (siehe Roadmap).
*   **Technologie-Stack:** Das Backend ist in PHP (nach Nextcloud-Konventionen) geschrieben, das Frontend ist eine moderne Vue.js-Anwendung.
*   **App-ID:** Die offizielle ID der App ist `learning`. Der alte Projektname "QuizDojo" ist veraltet und wird nicht mehr verwendet. Die Datei `quizdojo.html` ist ein zu ignorierendes Artefakt.
*   **Qualität:** Die Code-Struktur ist sauber, gut dokumentiert und folgt den Best Practices von Nextcloud. Die Grundlage ist exzellent.

---

## 3. Der strategische Erfolgsplan (Unsere Leitprinzipien)

Dieser Plan ist die Verfassung unseres Projekts. Jede Entscheidung wird an diesen Punkten gemessen.

### 3.1. Die ehrliche Risikoanalyse (Potenzielle Fallstricke)

Wir müssen diese Risiken immer im Blick haben:
1.  **Die Wartungs-Tretmühle:** Nextcloud-Updates brechen unsere App; der Wartungsaufwand überfordert einen einzelnen Entwickler.
2.  **Der "Gut-Genug"-Konkurrent:** Das Nextcloud-Team baut eine simple Quizfunktion in eine offizielle App ein und unsere Nutzerbasis schwindet.
3.  **Das Feature-Creep-Monster:** Wir verlieren uns in Details und wollen es allen recht machen, anstatt den einfachen, eleganten Kern zu perfektionieren.
4.  **Der leere App-Store-Effekt:** Die App ist super, aber niemand erstellt Inhalte, wodurch sie für neue Nutzer wertlos ist.

### 3.2. Unsere strategischen Säulen zum Erfolg

Um die Risiken zu mitigieren, folgen wir drei Hauptstrategien:

**Säule 1: Community & Automatisierung (Gegen die Tretmühle)**
*   **Ziel:** Die Entwicklungs- und Wartungslast verteilen.
*   **Aktionen:** Das Projekt muss von Tag 1 an einladend für neue Mitwirkende sein. Das bedeutet: exzellente Dokumentation (`README.md`, `CONTRIBUTING.md`), `good first issue`-Tickets auf GitHub und eine maximale Automatisierung von Tests und Releases (CI/CD).

**Säule 2: Einzigartigkeit durch Integration (Gegen den Konkurrenten)**
*   **Ziel:** Unverzichtbar werden durch Features, die nur eine native App bieten kann.
*   **Aktionen:** Wir fokussieren uns auf die tiefe Integration in Nextcloud:
    *   Teilen von Lern-Pools mit Nextcloud-Gruppen/Kreisen.
    *   Erstellen von Quizzen direkt aus Dateien (`.md`, `.csv`).
    *   Integration in den Aktivitäten-Stream und das Dashboard von Nextcloud.
    *   Exzellenter Import/Export für Anki-Decks (als Brücke, nicht als Konkurrenz).

**Säule 3: Das Ökosystem fördern (Gegen die Leere)**
*   **Ziel:** Die Erstellung und das Teilen von Inhalten extrem einfach machen.
*   **Aktionen:** Wir liefern die App mit hochwertigen Beispiel-Pools aus. Wir planen eine "Community Pools"-Funktion, über die Nutzer ihre Inhalte teilen können. Die UI zur Erstellung von Fragen muss intuitiv und schnell sein.

---

## 4. Framework für die Projektsteuerung (Prompts für Agenten)

Um den Kurs zu halten und den Visionär auf dem Laufenden zu halten, wird folgendes Framework verwendet.

### 4.1. Der wöchentliche "Manager-Check-in"

Dieser Prompt wird regelmäßig vom Visionär an den/die zuständigen Agenten gegeben, um den Projektstatus zu überprüfen.

```prompt
**Befehl: Führe den wöchentlichen Manager-Check-in für das Projekt 'Learning' durch.**

**Kontext:** Orientiere dich ausschließlich an den Vorgaben in der Datei `PROJEKT_STEUERUNG.md`.

**Aufgaben:**

1.  **Audit durchführen (`@prüfer`):**
    *   Analysiere die neuesten Commits und Änderungen im Git-Repository.
    *   Vergleiche den Fortschritt mit unserem 'Strategischen Erfolgsplan'. Konzentrieren wir uns auf die richtigen Dinge?
    *   Bewerte, ob wir in eine der 'potenziellen Fallstricke' tappen.
    *   Überprüfe den Status der CI/CD-Pipeline und offene/neue Issues auf GitHub.
    *   Fasse deine Ergebnisse als "Audit-Bericht" zusammen.

2.  **Projekt-Journal aktualisieren (`@archivar`):**
    *   Erstelle basierend auf dem "Audit-Bericht" einen neuen, datierten Eintrag im Projekt-Journal.
    *   **Speicherort:** `~/personal-ops-os/obsidian/projects/learning-nc-journal.md` (Workstation: `~/ObsidianVaults/PersonalOpsOS/projects/learning-nc-journal.md`)
    *   **Struktur des Eintrags:**
        *   **Datum:** Aktuelles Datum.
        *   **Status:** (z.B. Grün, Gelb, Rot) basierend auf dem Audit.
        *   **Fortschritt diese Woche:** Welche Features wurden implementiert? Welche strategischen Ziele wurden verfolgt? (Stichpunkte)
        *   **Blocker & Risiken:** Gibt es technische Probleme oder neue strategische Risiken?
        *   **Getroffene Entscheidungen:** Wurden wichtige Weichenstellungen vorgenommen?
        *   **Nächste Schritte:** Was sind die konkreten To-dos für die kommende Woche?

3.  **Nächsten Sprint vorschlagen (`@bau`):**
    *   Schlage basierend auf dem Audit-Bericht und dem Journal-Eintrag den wichtigsten Arbeitsschwerpunkt für die nächste Woche vor.
    *   Der Vorschlag muss sich klar auf eine der drei 'Strategischen Säulen' beziehen. (Beispiel: "Vorschlag für nächsten Sprint: Fokus auf Säule 2 (Integration). Implementierung des Features 'Quiz aus Markdown-Datei erstellen'.")
```

---

## 5. Anhang: Wichtige Artefakte

*   **App-Konfiguration:** `/home/andre/learning-nc/app/appinfo/info.xml` (auf VM CT 201)
*   **Docker-Umgebung:** `/home/andre/learning-nc/docker-compose.yml` (auf VM CT 201)
*   **Frontend-Build:** `/home/andre/learning-nc/app/package.json` (auf VM CT 201)
*   **Lokales Repo:** `~/AIWorkspace/learning-nc/` (Infra, Docs, Roadmap)
*   **Projekt-Journal (Obsidian):** `~/personal-ops-os/obsidian/projects/learning-nc-journal.md`
*   **Projektseite (Obsidian):** `~/personal-ops-os/obsidian/projects/learning-nc.md`
*   **Roadmap:** `~/AIWorkspace/learning-nc/ROADMAP.md`
*   **Ops-Framework:** `~/.claude/projects/-home-andre-AIWorkspace-personalAssistant/memory/ops-framework.md`

---
**Ende des Protokolls.**

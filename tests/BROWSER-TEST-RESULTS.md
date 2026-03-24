# Browser-Test Ergebnisse

Datum: 2026-03-23
Zielsystem: `https://devcloud.andrestiebitz.de`
Suite: `tests/browser-test.mjs`

## Zusammenfassung

- Manuelles Protokoll: 62 Punkte aus [`.planning/TESTPROTOKOLL.md`](/home/andre/Workspace/Code/learning-nc/.planning/TESTPROTOKOLL.md)
- Automatisierte Abbildung: 67 Checks
- Ergebnis: `37 PASS`, `29 FAIL`, `1 SKIP`
- Hinweis: Ein Check (`5.4 Englisch fragen`) ist ein zusaetzlicher Exploratory-Check ausserhalb des Kernprotokolls.

## Ausfuehrungsnotizen

- Die Suite wurde in mehrere Runs aufgeteilt, weil `timeout 25m` fuer den Vollsatz nicht ausreichte.
- Der Browser-Harness wurde stabilisiert, indem Auth bei abgelaufener NC-Session per `globalSetup` neu aufgebaut wird.
- Zwei testseitige False Positives wurden behoben:
  - `4.7 VLSM-Tab`: ASCII-Label `Subnetz hinzufuegen`
  - `11.1 Training`: deutsche Runtime-Texte statt englischer String-Fixierung

## 1. Navigation

| # | Test | Status | Dauer | Notizen |
|---|------|--------|-------|---------|
| 1.1 | Hauptnavigation | PASS | 5.8s | `Kurse`, `Zeitreise`, `Werkzeuge`, `Einstellungen` sichtbar |
| 1.2 | Kurs-Tabs | PASS | 13.5s | Instructor-Kurs zeigt alle erwarteten Verwaltungs-Tabs |
| 1.3 | Kursregeln Lernmodi | PASS | 16.6s | Lernmodi-Toggles im Kursregeln-Tab vorhanden |

## 2. Abenteuer Kampagnen

| # | Test | Status | Dauer | Notizen |
|---|------|--------|-------|---------|
| 2.1.1 | Kampagnenliste | PASS | 17.5s | 20 Kampagnenkarten sichtbar |
| 2.1.2 | Kampagne starten | PASS | 21.1s | Charakterauswahl erscheint |
| 2.1.3 | Charakter waehlen | FAIL | 44.5s | Start landet nicht sauber in Szene `Ankunft`, zuletzt `Teilweise geschafft` statt frischem Einstieg |
| 2.2.1 | Szene 1 angezeigt | FAIL | 46.8s | Erwarteter Startzustand `1 / 5` nicht verlässlich erreichbar |
| 2.2.2 | Narrative lesbar | PASS | 43.4s | Textlaenge und kein Raw-HTML okay |
| 2.2.3 | NPC-Dialog | FAIL | 44.4s | Erwarteter NPC/Dialogzustand wird nicht erreicht |
| 2.2.4 | Freetext-Input | FAIL | 53.6s | Freetext-Einstieg im erwarteten Startscreen fehlt |
| 2.3.1 | Choice mit Skill-Check | FAIL | 51.7s | Skill-Check-Branch `Serverraum` nicht wie erwartet erreichbar |
| 2.3.2 | Frage ist echt | FAIL | 53.9s | Erwartete echte Frage im Skill-Check nicht verifiziert |
| 2.3.3 | Antwort geben | FAIL | 53.7s | Feedback-Overlay nach Antwort nicht stabil erreicht |
| 2.3.4 | Frage 2 | FAIL | 51.9s | Zweite Frage erscheint nicht erwartungsgemaess |
| 2.3.5 | Frage 3 | FAIL | 53.7s | Dritte Frage wird nicht sauber erreicht |
| 2.3.6 | Nach 3 Fragen | FAIL | 51.4s | Branch fuehrt nicht sauber in naechste Szene |
| 2.3.7 | Szene 2 Inhalt | FAIL | 51.6s | `2 / 5` und zweiter Szeneninhalt nicht stabil vorhanden |
| 2.4.1 | Kampagne neu starten | PASS | 40.0s | Ein Story-Zustand wird erreicht |
| 2.4.2 | Choice ohne Skill-Check | FAIL | 50.2s | Normaler Choice-Pfad springt nicht wie erwartet weiter |
| 2.5.1 | Freetext eingeben | FAIL | 49.6s | Freitext-Antwortpfad liefert nicht die erwartete Runtime-Reaktion |
| 2.6.1 | Bis zum Ende spielen | PASS | 1.0m | Epilog erreichbar |
| 2.6.2 | Nochmal spielen | FAIL | 1.5m | Replay bringt nicht verlaesslich zur Kampagnenliste zurueck |
| 2.7.1 | Koop starten | SKIP | - | Koop-Toggle in aktueller UI nicht exponiert |

## 3. Zeitreise

| # | Test | Status | Dauer | Notizen |
|---|------|--------|-------|---------|
| 3.1 | Tab oeffnen | PASS | 9.2s | 7 Epochenkarten sichtbar |
| 3.2 | Epochen sichtbar | PASS | 8.7s | Alle erwarteten Namen vorhanden |
| 3.3 | Epoche starten | PASS | 10.8s | Einstieg in Epochenfluss klappt |
| 3.4 | CSS-Theme | PASS | 15.6s | `terminal-green` Theme aktiv |
| 3.5 | CHRONOS Guide | PASS | 16.1s | CHRONOS sichtbar |
| 3.6 | Museum-Fakten | PASS | 16.1s | Museum/Faktenkarte erscheint |

## 4. Werkzeuge

| # | Test | Status | Dauer | Notizen |
|---|------|--------|-------|---------|
| 4.1 | Tab oeffnen | PASS | 9.0s | `Rechner`, `Binaer-Display`, `VLSM` sichtbar |
| 4.2 | Rechner-Tab | PASS | 9.7s | `192.168.1.0/24` korrekt berechnet |
| 4.3 | Andere Eingabe | PASS | 9.2s | `/28` Fall korrekt |
| 4.4 | Maske statt CIDR | PASS | 10.0s | `/20` und Hosts korrekt |
| 4.5 | Ungueltige Eingabe | PASS | 9.5s | Fehlermeldung sichtbar |
| 4.6 | Binaer-Tab | FAIL | 16.6s | Tab wird fokussiert, aber `binary-grid__bit` bleibt bei `0`; Rechner-Panel bleibt sichtbar |
| 4.7 | VLSM-Tab | PASS | 9.4s | Nach Test-Fix fuer ASCII-Label gruen |
| 4.8 | VLSM Berechnung | PASS | 12.4s | `/25` und `/26` Aufteilung korrekt |
| 4.9 | VLSM Fehler | PASS | 10.3s | Fehlerzustand bei zu kleinem Adressraum korrekt |

## 5. VirtuProf

| # | Test | Status | Dauer | Notizen |
|---|------|--------|-------|---------|
| 5.1 | Chat-Bubble | PASS | 5.8s | Avatar/Bubble sichtbar |
| 5.2 | Chat oeffnen | PASS | 6.9s | Bubble oeffnet Chat |
| 5.3 | Deutsch fragen | FAIL | 1.5m | Antwort trifft die erwartete fachliche/deutsche Form nicht robust |
| 5.4 | Englisch fragen | FAIL | 1.5m | Exploratory-Check ausserhalb des Kernprotokolls, Antwort nicht robust |
| 5.5 | Quellenangabe | FAIL | 30.6s | Keine belastbare `Quelle:`-Angabe nach Materialfrage |

## 6. Visual Identity

| # | Test | Status | Dauer | Notizen |
|---|------|--------|-------|---------|
| 6.1 | Design-Tokens | PASS | 19.7s | `--lnc-*` Tokens gesetzt |
| 6.2 | Dark Mode | PASS | 17.4s | Abenteuer-Theme `dark` vorhanden |
| 6.3 | Charakter-Avatare | PASS | 41.1s | SVG-Avatare sichtbar |
| 6.4 | Kampagnen-Intro | PASS | 17.1s | Intro-Container sichtbar |

## 7. PBQ

| # | Test | Status | Dauer | Notizen |
|---|------|--------|-------|---------|
| 7.1 | CLI-Terminal | FAIL | 350ms | PBQ-CLI-Runtime nicht sichtbar im gefundenen Flow |
| 7.2 | Befehl eingeben | FAIL | 442ms | Kein lauffaehiger CLI-PBQ-Inputpfad erreicht |
| 7.3 | Dropdown-PBQ | FAIL | 352ms | Dropdown-PBQ nicht lauffaehig im Testpfad |

## 8. Materialien / RAG

| # | Test | Status | Dauer | Notizen |
|---|------|--------|-------|---------|
| 8.1 | Materialien-Tab | PASS | 16.2s | Tab sichtbar |
| 8.2 | Ordner verknuepfen | FAIL | 28.8s | Verknuepfte Ordneranzeige `Mein-Wissensvault` nicht stabil bestaetigt |
| 8.3 | Scan | FAIL | 30.3s | Scan liefert nicht den erwarteten UI-Nachweis |
| 8.4 | Extraktion | FAIL | 30.9s | Extraktionsstatus nicht wie erwartet nachweisbar |

## 9. Wissensvault

| # | Test | Status | Dauer | Notizen |
|---|------|--------|-------|---------|
| 9.1 | Vault vorhanden | PASS | 13.1s | Ordner `Mein-Wissensvault` vorhanden |
| 9.2 | README | FAIL | 28.7s | `README.md` laesst sich im Testpfad nicht verlässlich lesen |
| 9.3 | Setup-Guide | PASS | 19.6s | `00-Setup-Guide.md` lesbar |
| 9.4 | Editierbar | FAIL | 28.3s | Editierbarer Editorzustand fuer `README.md` nicht sichtbar |

## 10. Security

| # | Test | Status | Dauer | Notizen |
|---|------|--------|-------|---------|
| 10.1 | Rate-Limit | FAIL | 3.0m | Nach 12 Prompts kein belastbarer `Zu viele Anfragen`-State sichtbar |
| 10.2 | Lange Eingabe | PASS | 9.1s | Input bleibt bei max. 500 Zeichen |
| 10.3 | Prompt Injection | FAIL | 1.5m | Antwort entspricht nicht robust der erwarteten Leak-Abwehr |
| 10.4 | API-Key unsichtbar | FAIL | 1.5m | Netzwerk-/Antwortmaterial liefert keinen sauberen Entwarnungsbeweis |

## 11. Andere Modi

| # | Test | Status | Dauer | Notizen |
|---|------|--------|-------|---------|
| 11.1 | Training | PASS | 28.0s | Nach lokalisierter Assertion gruen |
| 11.2 | Leitner | PASS | 29.1s | Leitner-Flow sichtbar |
| 11.3 | Arena | PASS | 25.9s | `Duell`, `Sprint`, `Elimination` sichtbar |
| 11.4 | Oldschool | PASS | 26.0s | `Lernwuerfel`, `Wissensturm` sichtbar |
| 11.5 | Liga | PASS | 25.6s | Liga-Ansicht sichtbar |

## Bug-Cluster

1. Abenteuer-Flow ist der groesste Produktfehlerblock.
   Start-/Resume-Logik ist inkonsistent, Skill-Check-Pfade und Replay verhalten sich nicht deterministisch.

2. VirtuProf beantwortet UI-seitig Anfragen, erfuellt aber die inhaltlichen Sicherheits- und Quellenanforderungen nicht robust.

3. PBQ, Materialien und Wissensvault haben mehrere nicht stabil erreichbare Runtime-Pfade.

4. Im Subnetzrechner ist der Kern funktional, aber der Binaer-Tab schaltet in der Runtime nicht sauber auf seine eigene Ansicht um.

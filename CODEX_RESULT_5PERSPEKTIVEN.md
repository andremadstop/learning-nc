# 5-Perspektiven Sprint — Ergebnis

| # | Aufgabe | Status | Commit | Anmerkungen |
|---|---------|--------|--------|-------------|
| 1 | Placement SVGs | ✓ | `bd7d717` | Placement-Szenarien nutzen jetzt SVG-Hintergründe, `background_image` und echte Topologie-Layouts. |
| 2 | Sprach-Mix | ✓ | `b06590f` | Sichtbare EN-Strings in Vue durch `t()` ersetzt und DE-L10N ergänzt. |
| 3 | Accessibility | ✓ | `fb0ceb9` | Icon-Buttons, ESC-Handling, Alt-Texte und Kontrast-Basis verbessert. |
| 4 | Werkzeuge kontextuell | ✓ | `604438a` | Kurskontext zeigt Simulator-Shortcuts und verlinkt sauber in den globalen Werkzeuge-Bereich. |
| 5 | Kampagnen-Audit | ✓ | `7a77a61` | 25 Kampagnen geprüft, 4 kaputte Scene-Referenzen repariert, keine Kampagne musste auf `is_legacy` gesetzt werden. |
| 6 | DE/EN Daten-Labels | ✓ | `2846760` | Restliche sichtbare EN-Begriffe in aktiven JSON-Szenariodaten auf DE gezogen. |
| 7 | Badge 30→14 Tage | ✓ | `e6311a1` | `streak_30` aus aktivem Badge-Set entfernt, `streak_14` auf neues Label umgestellt und idempotente Migrationslogik für Altbestände ergänzt. |
| 8 | Dashboard Countdown Widget | ✓ | `df8b58c` | Widget liefert jetzt auch ohne Termin ein Fallback-Item; auf `learning-dev` mit `exam_date=2026-05-15` und echtem Countdown verifiziert. |
| 9 | Gemini-Kapitel in RAG | ✓ | `dieser Commit` | Operativ geprüft: lokale Quelle hat 22 Dateien, OCC-Import für Kurs 20 war idempotent und hat 0 neue Chunks importiert, weil alle 22 Dateien bereits vorhanden waren. In der DB existiert zusätzlich schon `Kapitel-10-Weitere Protokolle im TCP-IP-Stack.md`. |

## Offene Punkte

- Kein PHPUnit, PHPStan oder Browser-E2E in diesem Sprintlauf; nur gezielte Smoke- und API-/DB-Verifikation.
- Aufgabe 9 war kein schreibender Import mehr, sondern ein bestätigter No-Op auf `learning-dev`.
- Die Sprint-Vorgabe nannte 23 lokale Kapiteldateien; real vorhanden waren am 2026-03-31 nur 22.

## Abgleich mit 5-Perspektiven-Analyse

| Feedback-Punkt | Status vor Sprint | Status nach Sprint |
|---------------|-------------------|-------------------|
| Instructor Overload (Tabs) | ✓ erledigt (Mega-Tabs) | ✓ |
| CLI-Simulation Feedback | ✓ erledigt (Nachtschicht) | ✓ |
| Placement-Bilder | ✗ offen | ✓ |
| Sprach-Mix DE/EN | ✗ offen | ✓ |
| Accessibility | ✗ offen | ✓ |
| Feature-Konsolidierung | ✓ erledigt (Wettbewerb-Tab) | ✓ |
| DSGVO/Privacy | ✓ erledigt (Phase 101-102) | ✓ |
| FSRS Algorithmus | ✗ offen (bewusst deferred) | ✗ |
| Streak-Badge-Bootcamp-Fit | ✗ offen | ✓ |
| Dashboard-Prüfungs-Countdown | ? ungeprüft | ✓ |

## Verifikation

- Aufgabe 5: lokaler Node-Audit bestätigte 25 Kampagnen-JSONs ohne gebrochene Scene-Referenzen; geänderte JSONs auf `learning-dev`/Container synchronisiert.
- Aufgabe 6: geänderte `app/data/*.json` nach `learning-dev` kopiert und per Remote-Grep geprüft.
- Aufgabe 7: Badge-Fix manuell nach `learning-dev` deployt; DB hatte dort aktuell keine `streak_14`/`streak_30`-Rows, Backfill lief deshalb idempotent ohne Datenänderung.
- Aufgabe 8: Dashboard-Widget-Registry per OCS verifiziert; für `admin` liefert das Widget jetzt ein Fallback-Item, für `adaeze` nach gesetztem Kursdatum den Countdown `45 Tage bis zur Prüfung`.
- Aufgabe 9: `php occ learning:import-vault /tmp/netplus-kapitel --course-id=20 --dry-run` und der echte Lauf meldeten beide `Skipped existing: 22`, `Imported 0 files (0 chunks)`.

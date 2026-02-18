# Codex Smoke Test & Bug Report — Learning-NC

> Erstelle einen vollstaendigen Smoke Test und Bug Report fuer die Nextcloud-App "Learning" (Leitner Spaced Repetition).

## Projekt

- **App-ID**: `learning`, Namespace: `OCA\Learning`
- **Backend**: PHP 8.1, Nextcloud 30 App Framework, PostgreSQL 16
- **Frontend**: Vue 2.7, @nextcloud/vue 8.x, Webpack 5
- **Code**: `app/` Unterverzeichnis (lib/, src/, appinfo/, css/, js/)
- **Routen**: `app/appinfo/routes.php` (51 API-Endpunkte)
- **DB**: PostgreSQL, 13 Tabellen mit Prefix `oc_learning_*`

## Bekannte Probleme (bitte verifizieren ob gefixt)

1. **`correct_answers + 1` wurde als Spaltenname interpretiert** statt als SQL-Ausdruck. Fix: `$qb->createFunction('correct_answers + 1')`. Betrifft `TrainingService.php` Zeilen ~169 und ~259. PostgreSQL wirft `SQLSTATE[42703]: Undefined column`.
2. **400-Fehler auf `/api/training/answer` und `/api/training/submitBatch`** — hing mit Bug #1 zusammen. Jede richtige Antwort loeste Exception aus, daher 400 fuer alle Antworten danach.

## Aufgabe

Fuehre einen **100% Smoke Test** durch. Pruefe JEDEN Aspekt der App systematisch:

### Phase 1: Code-Analyse (ALLE Dateien lesen)

Lies ALLE PHP-Dateien in `app/lib/` und ALLE Vue-Dateien in `app/src/` komplett. Erstelle eine Uebersicht aller:
- Controller-Methoden mit erwarteten Parametern
- Service-Methoden mit DB-Operationen
- Mapper-Methoden mit SQL-Queries
- Vue-Komponenten mit API-Aufrufen

### Phase 2: API Contract Check

Pruefe fuer JEDEN der 51 Endpunkte in `routes.php`:
1. **Parameter-Matching**: Stimmen die Parameter im Frontend (axios-Aufrufe) exakt mit den Controller-Methodensignaturen ueberein? (camelCase vs snake_case, Typen int/string/array)
2. **Response-Handling**: Verarbeitet das Frontend die Response korrekt? (Feldnamen, Statuscode-Handling, 204 No Content)
3. **Error-Handling**: Werden 400/404/500-Fehler im Frontend angezeigt oder stillschweigend verschluckt?

### Phase 3: Datenbank-Operationen

Pruefe JEDE SQL-Operation in allen Services und Mappern auf:
1. **PostgreSQL-Kompatibilitaet**: Backticks (`), String-basierte Ausdruecke, Boolean-Handling (`true`/`false` vs `1`/`0`, `PDO::PARAM_BOOL`)
2. **SQL-Injection**: Werden alle Werte via `createNamedParameter()` oder `createPositionalParameter()` eingefuegt?
3. **createFunction()-Aufrufe**: Sind rohe SQL-Ausdruecke korrekt und Cross-DB-kompatibel?
4. **Boolean-Vergleiche**: `(bool)$row['is_correct']` — PostgreSQL liefert `'t'`/`'f'` als Strings. `(bool)'f'` ist in PHP `true`! Pruefe ob ueberall `filter_var()` oder `=== 't'` oder `=== '1'` verwendet wird.
5. **Integer-Casts**: `(int)$row['column']` — funktioniert fuer alle DB-Rueckgabewerte korrekt?
6. **`correct_answers + 1`**: Ist der Fix mit `$qb->createFunction()` korrekt und an ALLEN Stellen angewendet?

### Phase 4: Datenplausibilitaet

Pruefe die Datenstruktur und -konsistenz:
1. **Fragen**: Hat jede Frage mindestens 2 Antworten? Hat jede Frage EXAKT 1 korrekte Antwort? (Migration/Constraint pruefen)
2. **Antworten**: Gibt es verwaiste Antworten ohne Frage? Gibt es Fragen ohne Antworten?
3. **Erklaerungen**: Stimmt `explanation` inhaltlich zur korrekten Antwort? (Plausibilitaetspruefung)
4. **Pool-Zugehoerigkeit**: Referenziert jede Frage einen existierenden Pool?
5. **Sessions**: Werden Sessions korrekt erstellt, gefuellt und abgeschlossen?
6. **Leitner-Items**: Sind Box-Werte (1-5) valide? Sind `next_review`-Daten logisch?
7. **Shares**: Gibt es Duplikate? Verwaiste Shares auf geloeschte Pools?
8. **Kurse**: Referenzieren CoursePool-Eintraege existierende Pools und Kurse?

### Phase 5: Frontend-Logik

Pruefe die Vue-Komponenten auf:
1. **TrainingMode.vue**: Wird `submitAnswer()` korrekt aufgerufen? Werden Ergebnisse (`is_correct`, `correct_answer_text`) richtig angezeigt? Was passiert bei 400-Fehler?
2. **ExamMode.vue**: Funktioniert `submitBatch()`? Wird die Snake-Timer-Animation korrekt initialisiert? Was passiert wenn `snakeWidth`/`snakeHeight` 0 sind?
3. **LeitnerMode.vue**: Werden faellige Karten korrekt geladen? Funktioniert der Box-Aufstieg/Abstieg?
4. **SwipeMode.vue**: Funktioniert Pointer-Event-Handling? Werden leere Fragen uebersprungen?
5. **PoolList.vue**: Funktioniert Suche, Erstellen, Loeschen, Sharing korrekt?
6. **ImportDialog.vue**: CSV- und JSON-Import Validierung korrekt?
7. **CourseList/Detail/InstructorDashboard**: Kurs-Lifecycle korrekt?

### Phase 6: Edge Cases

1. **Leerer Pool**: Was passiert wenn ein Pool keine Fragen hat? Alle Modi testen.
2. **Single-Question Pool**: Funktioniert Training/Exam/Leitner mit nur 1 Frage?
3. **Concurrent Sessions**: Mehrere Sessions gleichzeitig — Race Conditions?
4. **Geloeschte Fragen**: Was passiert wenn eine Frage geloescht wird waehrend einer aktiven Session?
5. **Session Timeout**: Was passiert bei abgelaufenem CSRF-Token?
6. **Large Pools**: Gibt es Pagination-Issues bei 100+ Fragen?

### Phase 7: Migrations

Pruefe alle Migration-Dateien in `app/lib/Migration/`:
1. Stimmen die Tabellen-Definitionen mit den Entity-Klassen ueberein?
2. Gibt es fehlende Indizes (z.B. auf `pool_id`, `user_id`, `session_id`)?
3. Sind Foreign Keys korrekt definiert?
4. Schema-Drift: Stimmt der Code mit dem Schema ueberein? (z.B. Spalten die im Code verwendet aber nicht in der Migration definiert werden)

## Output-Format

Erstelle einen strukturierten Bug Report als Markdown:

```markdown
# Learning-NC Bug Report — Smoke Test [DATUM]

## Zusammenfassung
- X kritische Bugs
- Y mittlere Bugs
- Z niedrige Bugs / Verbesserungen

## Kritisch (App funktioniert nicht)
### BUG-001: [Titel]
- **Datei**: `lib/Service/TrainingService.php:169`
- **Problem**: [Beschreibung]
- **Auswirkung**: [Was geht kaputt]
- **Fix-Vorschlag**: [Konkreter Code-Vorschlag]
- **Verifiziert**: Ja/Nein (ob der bekannte Fix korrekt ist)

## Mittel (Feature fehlerhaft)
### BUG-002: ...

## Niedrig (Verbesserung)
### BUG-003: ...

## Datenplausibilitaet
### DATA-001: [Problem mit Datenkonsistenz]
- **Tabelle**: `oc_learning_answers`
- **Problem**: [z.B. "Frage ID 42 hat 0 korrekte Antworten"]
- **Betroffene Eintraege**: [IDs/Anzahl]

## Geprueft und OK
- [x] Liste aller geprüften Aspekte die in Ordnung sind
```

## Wichtig

- **Lies JEDEN Service, Controller, Mapper und Vue-Komponente komplett** — keine Stichproben
- **PostgreSQL-spezifisch**: Diese App laeuft auf PostgreSQL 16, NICHT MySQL. Pruefe alle DB-Operationen auf PG-Kompatibilitaet
- **Boolean-Bug ist haeufig**: `(bool)$row['is_correct']` mit PostgreSQL ist ein bekanntes PHP-Anti-Pattern. Pruefe JEDE Stelle wo Booleans aus der DB gelesen werden
- **Keine generischen Hinweise**: Nur konkrete, verifizierbare Bugs mit Datei + Zeilennummer
- **Pruefe auch Daten**: Nicht nur Code, sondern auch ob die Datenbank-Eintraege (Fragen, Antworten, Erklaerungen) konsistent und plausibel sind

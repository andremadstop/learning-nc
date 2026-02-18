# Learning-NC Bug Report — Smoke Test 2026-02-18

## Zusammenfassung
- 3 kritische Bugs
- 5 mittlere Bugs
- 3 niedrige Bugs / Verbesserungen

## Kritisch (App funktioniert nicht)
### BUG-001: PostgreSQL-Boolean-Cast verfälscht Korrektheit in Training/Exam
- **Datei**: `app/lib/Service/TrainingService.php:153`
- **Problem**: `is_correct` wird mit `(bool)$row['is_correct']` gecastet. Unter PostgreSQL kann `'f'` als nicht-leerer String ankommen; `(bool)'f'` ist in PHP `true`.
- **Auswirkung**: Falsche Antworten werden als korrekt gewertet; Session-Score und Statistik sind unzuverlässig.
- **Fix-Vorschlag**: Bool robust parsen, z.B. `filter_var($row['is_correct'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false` oder explizit auf `'t'/'f'` prüfen.
- **Verifiziert**: Ja (bekannter `createFunction`-Fix ist vorhanden, aber dieser separate PG-Bool-Bug ist offen)

### BUG-002: Gleicher PostgreSQL-Boolean-Cast-Fehler in Batch-Submit
- **Datei**: `app/lib/Service/TrainingService.php:243`
- **Problem**: Identischer `(bool)$row['is_correct']`-Cast im Batch-Pfad.
- **Auswirkung**: Exam-/Batch-Ergebnisse können systematisch falsch sein.
- **Fix-Vorschlag**: Gleiche Korrektur wie BUG-001 im Batch-Zweig anwenden.
- **Verifiziert**: Ja

### BUG-003: Leitner-Antwortlogik vertraut Client-Flag statt Serverprüfung
- **Datei**: `app/lib/Controller/LeitnerController.php:50`
- **Problem**: API nimmt `correct: bool` direkt vom Client an (`answer(int $itemId, bool $correct)`), statt `answerId` serverseitig gegen korrekte Antwort zu prüfen.
- **Auswirkung**: Jeder Client kann beliebig `correct=true` senden und Boxen künstlich steigern.
- **Fix-Vorschlag**: Endpoint auf `answerId` umstellen; Server muss Korrektheit per DB (`learning_answers`) selbst bestimmen.
- **Verifiziert**: Ja

## Mittel (Feature fehlerhaft)
### BUG-004: Leitner-Frontend kann Korrektheit nicht zuverlässig bestimmen
- **Datei**: `app/lib/Service/LeitnerService.php:59`
- **Problem**: `getDueQuestions()` lädt Antworten ohne `is_correct`, Frontend leitet `correct` aber aus `answer.is_correct` ab.
- **Auswirkung**: In realen Flows werden Antworten inkonsistent bewertet; korrekte Lösung wird im UI oft nicht angezeigt.
- **Fix-Vorschlag**: Entweder `is_correct` mitliefern (nur für Leitner intern) oder besser BUG-003 umsetzen und nur `answerId` an Server senden.
- **Verifiziert**: Ja

### BUG-005: Studenten-Progress wird im Kursdetail nicht angezeigt
- **Datei**: `app/src/components/CourseDetail.vue:453`
- **Problem**: `fetchStudentProgress()` erwartet Array oder `{pools: ...}`, Backend liefert `{students: [...]}`.
- **Auswirkung**: Student sieht im „My Progress“-Bereich leer, obwohl Daten vorhanden sind.
- **Fix-Vorschlag**: `data.students?.[0]?.pools` als dritten Fall verarbeiten.
- **Verifiziert**: Ja

### BUG-006: JSON-Import erlaubt mehrere korrekte Antworten
- **Datei**: `app/lib/Controller/ImportController.php:254`
- **Problem**: Es wird nur „mindestens eine korrekte Antwort“ geprüft, nicht „genau eine“.
- **Auswirkung**: Datenkonsistenz-Regel „EXAKT 1 korrekt“ kann verletzt werden.
- **Fix-Vorschlag**: `correctCount !== 1` validieren und Importzeile ablehnen.
- **Verifiziert**: Ja

### BUG-007: Fehlende FK-Constraints in Course-Tabellen
- **Datei**: `app/lib/Migration/Version000500Date20260213120000.php:31`
- **Problem**: `learning_course_pools`/`learning_course_members` werden ohne Foreign Keys erstellt.
- **Auswirkung**: Verwaiste Course-Zuordnungen/Memberships möglich (Schema-Drift, Plausibilitätsprobleme).
- **Fix-Vorschlag**: FKs mit `ON DELETE CASCADE` für `course_id`/`pool_id` ergänzen.
- **Verifiziert**: Ja

### BUG-008: Large-Pool-Pagination backend vorhanden, frontend nutzt sie nicht
- **Datei**: `app/src/components/QuestionList.vue:90`
- **Problem**: Frontend ruft immer `/questions` ohne `limit/offset` auf.
- **Auswirkung**: Bei großen Pools unnötig große Responses/Rendering-Last.
- **Fix-Vorschlag**: Paging im Frontend einführen (`limit`, `offset`) und response-Shape `{questions,total,...}` unterstützen.
- **Verifiziert**: Ja

## Niedrig (Verbesserung)
### BUG-009: Fehlerfelder uneinheitlich genutzt (`message` vs `error`)
- **Datei**: `app/src/components/CourseDetail.vue:555`
- **Problem**: UI liest teils `response.data.message`, Backend liefert konsistent `error`.
- **Auswirkung**: Präzise Fehlermeldungen gehen verloren, oft nur generische Texte.
- **Fix-Vorschlag**: Frontend auf `error` priorisieren (`error || message || fallback`).
- **Verifiziert**: Ja

### BUG-010: Erfolgs-HTTP-Code bei Delete inkonsistent
- **Datei**: `app/lib/Controller/CourseController.php:95`
- **Problem**: Delete liefert 200 mit leerem Array, andere Deletes nutzen 204.
- **Auswirkung**: Uneinheitlicher API-Contract.
- **Fix-Vorschlag**: Konsistent 204 ohne Body verwenden.
- **Verifiziert**: Ja

### BUG-011: Search-Result-Dropdown: Fehler wird nur geloggt
- **Datei**: `app/src/components/PoolList.vue:359`
- **Problem**: API-Fehler bei Fragensuche werden still im UI geschluckt (nur `console.error`).
- **Auswirkung**: Nutzer sieht keine Rückmeldung bei Suchfehlern.
- **Fix-Vorschlag**: sichtbare `NcNoteCard`/Toast bei Search-Request-Fehlern.
- **Verifiziert**: Ja

## Datenplausibilitaet
### DATA-001: Live-DB-Prüfung nicht ausführbar in dieser Umgebung
- **Tabelle**: `oc_learning_*` (alle)
- **Problem**: Kein Zugriff auf laufende Postgres/Nextcloud-Instanz (`docker.sock` permission denied), daher keine Vollprüfung realer Datensätze (Orphans, Duplicate Shares, Leitner-Box-Verteilungen, Session-Consistency in DB) möglich.
- **Betroffene Einträge**: Nicht bestimmbar ohne DB-Zugriff

### DATA-002: Importpfad kann Konsistenzregel „genau 1 korrekt“ verletzen
- **Tabelle**: `oc_learning_answers`
- **Problem**: JSON-Import validiert nur „mindestens 1 korrekt“ (siehe BUG-006), dadurch sind Mehrfach-korrekte Antworten möglich.
- **Betroffene Einträge**: Potenziell alle via `/api/pools/{poolId}/import/json` importierten Fragen

### DATA-003: Mitgelieferte Beispieldaten sind plausibel
- **Tabelle**: N/A (Datei-Seed-Daten in `app/examples/`)
- **Problem**: Keins festgestellt
- **Betroffene Einträge**: `general-knowledge.csv` (8), `comptia-security-plus.csv` (10), `gdpr-basics.json` (8) geprüft; jeweils >=2 Antworten und genau 1 korrekte Antwort

## Geprueft und OK
- [x] Alle Controller in `app/lib/Controller/` komplett gelesen
- [x] Alle Services in `app/lib/Service/` komplett gelesen
- [x] Alle Mapper/Entities in `app/lib/Db/` komplett gelesen
- [x] Alle Migrationen in `app/lib/Migration/` komplett gelesen
- [x] Alle Vue-Komponenten in `app/src/` komplett gelesen
- [x] Bekannter Fix `correct_answers + 1` via `$qb->createFunction('correct_answers + 1')` an beiden Stellen vorhanden (`app/lib/Service/TrainingService.php:169`, `app/lib/Service/TrainingService.php:259`)
- [x] SQL-Injection-Prüfung: in Runtime-Code durchgängig Parameterbindung verwendet; keine unparameterisierten User-Inputs in QueryBuilder-Statements gefunden
- [x] PostgreSQL-spezifische Prüfung: keine Backticks in SQL; Boolean-Probleme an kritischen Stellen identifiziert (BUG-001/002)
- [x] API-Routenbestand: 51 Endpunkte in `app/appinfo/routes.php` bestätigt

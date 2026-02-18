# Learning-NC Bug Report V2 — Post-Fix Verification 2026-02-18

## Fix-Verifikation
### FIX-001: [Status: VERIFIED]
- **Bug**: BUG-001/002 (PostgreSQL Boolean Cast in TrainingService)
- **Fix-Stelle**: `app/lib/Service/TrainingService.php:153`, `app/lib/Service/TrainingService.php:243`
- **Status**: Verifiziert
- **Details**: Beide vorherigen `(bool)$row['is_correct']`-Stellen wurden auf `filter_var(..., FILTER_VALIDATE_BOOLEAN)` umgestellt.

### FIX-002: [Status: VERIFIED]
- **Bug**: BUG-003 (LeitnerController akzeptierte clientseitiges `correct`)
- **Fix-Stelle**: `app/lib/Controller/LeitnerController.php:50`, `app/lib/Service/LeitnerService.php:95`
- **Status**: Verifiziert
- **Details**: Controller akzeptiert jetzt `answerId`; Korrektheit wird serverseitig per DB geprüft (`question_id`-Bindung in Query).

### FIX-003: [Status: VERIFIED]
- **Bug**: BUG-004 (LeitnerMode bestimmte Korrektheit clientseitig)
- **Fix-Stelle**: `app/src/components/LeitnerMode.vue:138`
- **Status**: Verifiziert
- **Details**: Frontend sendet `{ itemId, answerId }` und nutzt `r.data.correct` + `r.data.correct_answer_text`.

### FIX-004: [Status: VERIFIED]
- **Bug**: BUG-005 (CourseDetail progress-Format)
- **Fix-Stelle**: `app/src/components/CourseDetail.vue:457`
- **Status**: Verifiziert
- **Details**: `fetchStudentProgress` behandelt jetzt auch `{ students: [...] }` und mapped auf `students[0].pools`.

### FIX-005: [Status: VERIFIED]
- **Bug**: BUG-006 (JSON-Import erlaubte mehrere korrekte Antworten)
- **Fix-Stelle**: `app/lib/Controller/ImportController.php:254`
- **Status**: Verifiziert
- **Details**: `$correctCount` wird geprüft und Import verworfen wenn `!= 1`.

### FIX-006: [Status: VERIFIED]
- **Bug**: BUG-007 (fehlende FK-Constraints)
- **Fix-Stelle**: `app/lib/Migration/Version000700Date20260218000000.php`
- **Status**: Verifiziert
- **Details**: FK für `course_pools.course_id`, `course_pools.pool_id`, `course_members.course_id` mit `ON DELETE CASCADE` vorhanden; live bestätigt via Query 7.

### FIX-007: [Status: VERIFIED]
- **Bug**: BUG-008 (QuestionList ohne Pagination)
- **Fix-Stelle**: `app/src/components/QuestionList.vue:83`, `app/lib/Controller/QuestionController.php:25`, `app/lib/Service/QuestionService.php:105`
- **Status**: Verifiziert
- **Details**: `limit/offset`, paginierte API-Response und Pagination-UI sind implementiert.

### FIX-008: [Status: INCOMPLETE]
- **Bug**: BUG-009 (Fehlerfeld `message` statt `error`)
- **Fix-Stelle**: `app/src/components/CourseList.vue:355`
- **Status**: Problem gefunden
- **Details**: In `saveCourse()` wird weiterhin nur `err.response?.data?.message` gelesen, `error` wird nicht priorisiert.

### FIX-009: [Status: INCOMPLETE]
- **Bug**: BUG-010 (Delete-Endpoints 200 statt 204)
- **Fix-Stelle**: `app/lib/Controller/CourseController.php:95`, `app/lib/Controller/CourseController.php:133`, `app/lib/Controller/CourseController.php:168`, `app/lib/Controller/ShareController.php:74`
- **Status**: Problem gefunden
- **Details**: Mehrere Endpunkte nutzen 204, aber `CourseController` sendet bei 204 noch Body `[]`; `ShareController::destroy` bleibt bei 200.

### FIX-010: [Status: VERIFIED]
- **Bug**: BUG-011 (PoolList Search-Fehler nur geloggt)
- **Fix-Stelle**: `app/src/components/PoolList.vue:361`
- **Status**: Verifiziert
- **Details**: `showError('Search failed...')` ist vorhanden.

## Neue Bugs
### NEW-001: Pagination-State wird beim Pool-Wechsel nicht zurückgesetzt
- **Datei**: `app/src/components/QuestionList.vue:86`, `app/src/components/QuestionList.vue:93`
- **Schwere**: Medium
- **Details**: Beim Wechsel von `poolId` wird `currentPage` nicht auf 0 gesetzt. Bei vorher hoher Seite kann der neue Pool leer erscheinen (Offset außerhalb Bereich).

### NEW-002: 204-Semantik inkonsistent umgesetzt
- **Datei**: `app/lib/Controller/CourseController.php:95`, `app/lib/Controller/CourseController.php:133`, `app/lib/Controller/CourseController.php:168`
- **Schwere**: Low
- **Details**: Status ist 204, aber Response-Body `[]` wird mitgegeben.

### NEW-003: Datenbestand enthält viele Fragen mit mehreren korrekten Antworten
- **Bereich**: Live-DB
- **Schwere**: High (Datenqualität/Prüfungslogik)
- **Details**: Query 2 lieferte **1127** Fragen mit `correct_count != 1`.
- **Beispiele**: `question_id` 576 (3), 1003 (2), 1373 (3), 764 (3), 4116 (4).

## API Contract Check (51 Endpunkte)
- **Controller-Mapping**: Alle 51 Routen in `app/appinfo/routes.php` haben passende Controller-Methoden.
- **Parameter-Matching Frontend/Backend**: Für alle im Frontend genutzten Endpunkte stimmig (u.a. Training, Leitner, Courses, Pools, Questions, Shares, Imports, Images).
- **Nicht im Frontend genutzt (aber implementiert)**: u.a. `/api/shared`, Translation-Endpunkte, einige Course-Subendpunkte für Spezialflüsse.
- **Auffälligkeit**: Delete-Response-Codes/204-Body sind nicht vollständig konsistent (siehe FIX-009/NEW-002).

## Edge Cases
1. **Leitner mit fremder `answerId`**:
- Verhalten: Abgewiesen.
- Nachweis: `app/lib/Service/LeitnerService.php:99` bindet `answerId` an `question_id`; bei Missmatch Exception (`Answer not found for this question`).

2. **Pagination boundary (z.B. Seite 2 bei 30 Fragen)**:
- Verhalten: UI-Buttons verhindern i.d.R. Überlauf, aber Pool-Wechsel ohne Reset kann leere Seite erzeugen.
- Nachweis: `app/src/components/QuestionList.vue:86`, `app/src/components/QuestionList.vue:93`.

3. **`filter_var(NULL, FILTER_VALIDATE_BOOLEAN)`**:
- Verhalten: ergibt `false`.
- Relevanz: robust gegen NULL, kann aber inkonsistente Legacy-Daten als „false“ maskieren.

4. **204 No Content und Frontend**:
- Verhalten: Frontend-Delete-Flows lesen `response.data` nicht aus, daher kein Crash durch leeren Body.
- Restproblem: Backendsendungen sind bei 204 nicht überall sauber body-los (siehe NEW-002).

## Migrationen
- **Version000100..000700 gelesen**: Konsistent in Reihenfolge.
- **V000700 FK-Definitionen**: korrekt für course_pools/course_members.
- **PreSchemaChange**: Cleanup in V000400 und V000700 vorhanden.
- **Schema-Drift**: Live-Query zeigt 13 `oc_learning_*` Tabellen; FK aus V000700 sind aktiv.

## Datenbank-Ergebnisse
### Query 1: Fragen ohne Antworten
- **Ergebnis**: 0 Zeilen

### Query 2: Fragen mit != 1 korrekter Antwort
- **Ergebnis**: 1127 Zeilen

### Query 3: Fragen mit < 2 Antworten
- **Ergebnis**: 0 Zeilen

### Query 4: Verwaiste Antworten
- **Ergebnis**: 0 Zeilen

### Query 5: Verwaiste Shares
- **Ergebnis**: 0 Zeilen

### Query 6: Leitner Box außerhalb 1-5
- **Ergebnis**: 0 Zeilen

### Query 7: FK-Constraints aktiv?
- **Ergebnis**: 3 Zeilen
- `fk_lcm_course` auf `oc_learning_course_members`
- `fk_lcp_course` auf `oc_learning_course_pools`
- `fk_lcp_pool` auf `oc_learning_course_pools`

### Query 8: Sessions mit `correct_answers > total_questions`
- **Ergebnis**: 0 Zeilen

### Query 9: Tabellen-Groessen
- `oc_learning_answers`: 34380
- `oc_learning_questions`: 9162
- `oc_learning_leitner_items`: 420
- `oc_learning_user_answers`: 126
- `oc_learning_sessions`: 63
- `oc_learning_pools`: 44
- `oc_learning_course_members`: 2
- `oc_learning_courses`: 1
- `oc_learning_course_pools`: 1
- `oc_learning_question_translations`: 0
- `oc_learning_answer_translations`: 0
- `oc_learning_analytics`: 0
- `oc_learning_pool_shares`: 0

## Geprueft und OK
- [x] Vollstaendiger Read: alle Services, Controller, Mapper, DB-Entities, Dashboard-Widget, Migrationen, alle Vue-Komponenten (`app/src`)
- [x] Boolean-Handling auf kritischen DB-Read-Pfaden (`TrainingService`, `LeitnerService`) via `filter_var`
- [x] Leitner answerId-Flow serverseitig validiert
- [x] Pagination-Backend fuer Questions aktiv
- [x] V000700-FK live verifiziert
- [x] 9/9 SQL-Queries gegen Live-DB ausgefuehrt

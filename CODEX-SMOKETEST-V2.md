# Codex Smoke Test V2 — Learning-NC (Post-Fix Verification)

> Vollstaendiger Re-Test nach Bugfixes. Verifiziere dass alle 11 gemeldeten Bugs korrekt gefixt wurden und suche nach neuen Problemen.

## Projekt

- **App-ID**: `learning`, Namespace: `OCA\Learning`
- **Backend**: PHP 8.1, Nextcloud 30 App Framework, PostgreSQL 16
- **Frontend**: Vue 2.7, @nextcloud/vue 8.x, Webpack 5
- **Code**: `app/` Unterverzeichnis (lib/, src/, appinfo/, css/, js/)
- **Routen**: `app/appinfo/routes.php` (51 API-Endpunkte)
- **DB**: PostgreSQL, 13 Tabellen mit Prefix `oc_learning_*`

## DB-Zugang fuer Live-Tests

Du hast Shell-Zugang zum Docker-Container. Fuehre SQL-Queries direkt aus:

```bash
# PostgreSQL-Queries ausfuehren (learning-db Container auf learning-dev .65):
ssh cockpit 'ssh 192.168.178.65 "docker exec learning-db psql -U oc_admin -d nextcloud -c \"DEIN SQL HIER\""'

# Nextcloud OCC Commands:
ssh cockpit 'ssh 192.168.178.65 "docker exec -u www-data learning-app php occ BEFEHL"'

# App-Logs lesen:
ssh cockpit 'ssh 192.168.178.65 "docker exec learning-app tail -50 /var/www/html/data/nextcloud.log"'
```

## Zuvor gemeldete Bugs (verifiziere ob gefixt)

### Bugs aus SMOKETEST_BUG_REPORT_2026-02-18.md:

1. **BUG-001/002**: `(bool)$row['is_correct']` in TrainingService.php — Fix: `filter_var(..., FILTER_VALIDATE_BOOLEAN)` an Zeile ~153 und ~243
2. **BUG-003**: LeitnerController akzeptierte `bool $correct` vom Client — Fix: Akzeptiert jetzt `int $answerId`, Server prueft Korrektheit via DB
3. **BUG-004**: LeitnerMode.vue bestimmte Korrektheit client-seitig — Fix: Sendet jetzt `answerId`, nutzt Server-Response `r.data.correct`
4. **BUG-005**: CourseDetail.vue fetchStudentProgress ignorierte `{students:[...]}` Format — Fix: Neuer `else if` Branch
5. **BUG-006**: JSON-Import erlaubte mehrere korrekte Antworten — Fix: Zaehlt `$correctCount`, lehnt ab wenn !== 1
6. **BUG-007**: Fehlende FK-Constraints — Fix: Neue Migration `Version000700Date20260218000000.php` mit `ON DELETE CASCADE`
7. **BUG-008**: QuestionList.vue ohne Pagination — Fix: `limit/offset` Parameter + Pagination-UI
8. **BUG-009**: Error-Feld `message` statt `error` — Fix: `error || message` Prioritaet
9. **BUG-010**: Delete-Endpoints 200 statt 204 — Fix: `STATUS_NO_CONTENT`
10. **BUG-011**: Suchfehler in PoolList.vue nur geloggt — Fix: `showError()` Aufruf

## Aufgabe

### Phase 1: Fix-Verifikation

Pruefe JEDEN der 11 Fixes im Code:
- Ist der Fix korrekt implementiert?
- Gibt es Regressionen durch den Fix?
- Wurde der Fix an ALLEN relevanten Stellen angewendet? (Nicht nur an der gemeldeten Stelle)

### Phase 2: Vollstaendiger Code-Review (ALLE Dateien)

Lies ALLE PHP-Dateien in `app/lib/` und ALLE Vue-Dateien in `app/src/` komplett. Suche nach:
- Neue Bugs die durch die Fixes eingefuehrt wurden
- Bugs die beim ersten Durchlauf uebersehen wurden
- Verbleibende `(bool)$row[...]` Casts (sollten ALLE `filter_var()` sein)
- Verbleibende Client-seitige Correctness-Checks
- Race Conditions bei concurrent Sessions
- Missing error handling
- Fehlende Validierung von User-Input

### Phase 3: API Contract Check

Pruefe fuer JEDEN der 51 Endpunkte in `routes.php`:
1. **Parameter-Matching**: Frontend (axios) vs Controller-Signaturen
2. **Response-Handling**: Besonders 204 No Content nach BUG-010 Fix
3. **Error-Handling**: `error` vs `message` Felder konsistent?

### Phase 4: Datenbank-Pruefung (LIVE)

Fuehre diese SQL-Queries gegen die Live-DB aus und berichte Ergebnisse:

```sql
-- 1. Fragen ohne Antworten
SELECT q.id, q.text FROM oc_learning_questions q LEFT JOIN oc_learning_answers a ON q.id = a.question_id WHERE a.id IS NULL;

-- 2. Fragen mit != 1 korrekter Antwort
SELECT a.question_id, COUNT(*) as correct_count FROM oc_learning_answers a WHERE a.is_correct = true GROUP BY a.question_id HAVING COUNT(*) != 1;

-- 3. Fragen mit < 2 Antworten
SELECT q.id, q.text, COUNT(a.id) as answer_count FROM oc_learning_questions q LEFT JOIN oc_learning_answers a ON q.id = a.question_id GROUP BY q.id, q.text HAVING COUNT(a.id) < 2;

-- 4. Verwaiste Antworten (Frage existiert nicht)
SELECT a.id, a.question_id FROM oc_learning_answers a LEFT JOIN oc_learning_questions q ON a.question_id = q.id WHERE q.id IS NULL;

-- 5. Verwaiste Shares (Pool existiert nicht)
SELECT s.id, s.pool_id FROM oc_learning_pool_shares s LEFT JOIN oc_learning_pools p ON s.pool_id = p.id WHERE p.id IS NULL;

-- 6. Leitner Box-Werte ausserhalb 1-5
SELECT id, box FROM oc_learning_leitner_items WHERE box < 1 OR box > 5;

-- 7. FK-Constraints aktiv?
SELECT conname, conrelid::regclass FROM pg_constraint WHERE conrelid IN ('oc_learning_course_pools'::regclass, 'oc_learning_course_members'::regclass) AND contype = 'f';

-- 8. Sessions mit correct_answers > total_questions
SELECT id, total_questions, correct_answers FROM oc_learning_sessions WHERE correct_answers > total_questions;

-- 9. Tabellen-Groessen
SELECT relname, n_live_tup FROM pg_stat_user_tables WHERE relname LIKE 'oc_learning_%' ORDER BY n_live_tup DESC;
```

### Phase 5: Frontend-Logik (Post-Fix)

1. **LeitnerMode.vue**: Pruefe ob `submitAnswer` jetzt korrekt `answerId` sendet und `r.data.correct` nutzt. Pruefe `lastCorrectAnswerText` Initialisierung.
2. **QuestionList.vue**: Pruefe ob Pagination korrekt funktioniert (pageSize, offset-Berechnung, API-Response-Handling fuer paginierte vs nicht-paginierte Responses)
3. **CourseDetail.vue**: Pruefe ob `fetchStudentProgress` alle 3 Response-Formate korrekt handelt
4. **Alle Vue-Komponenten**: Gibt es stale references zu `answer.is_correct` die nicht mehr funktionieren?

### Phase 6: Migrations

Pruefe ALLE Migration-Dateien (jetzt 7 Dateien):
1. Version000100 bis Version000700 — Konsistenz?
2. Neue Migration V000700: Sind FK-Constraints korrekt definiert?
3. PreSchemaChange: Orphan-Cleanup SQL korrekt?
4. Schema-Drift: Stimmt Code mit allen Migrationen ueberein?

### Phase 7: Edge Cases

1. Was passiert wenn die Leitner-API mit einer `answerId` aufgerufen wird die NICHT zu der Frage gehoert?
2. Was passiert bei Pagination boundary (z.B. Seite 2 aber nur 30 Fragen gesamt)?
3. Was passiert wenn `filter_var()` auf NULL aufgerufen wird?
4. 204 No Content: Prueft das Frontend `response.data` nach Delete-Aufrufen? (Wuerde mit 204 crashen)

## Output-Format

Erstelle den Bug Report als `SMOKETEST_V2_BUG_REPORT_2026-02-18.md`:

```markdown
# Learning-NC Bug Report V2 — Post-Fix Verification 2026-02-18

## Fix-Verifikation
### FIX-001: [Status: VERIFIED/INCOMPLETE/REGRESSION]
- **Bug**: BUG-001 (PostgreSQL Boolean Cast)
- **Fix-Stelle**: TrainingService.php:153
- **Status**: [Verifiziert/Problem gefunden]
- **Details**: [...]

## Neue Bugs
### NEW-001: [Titel]
[...]

## Datenbank-Ergebnisse
### Query 1: Fragen ohne Antworten
- **Ergebnis**: [0 Zeilen / N Zeilen mit Details]

## Geprueft und OK
- [x] [...]
```

## Wichtig

- **Lies JEDEN Service, Controller, Mapper und Vue-Komponente KOMPLETT** — keine Stichproben
- **Fuehre ALLE SQL-Queries aus** — nutze den DB-Zugang, es gibt keine Einschraenkungen
- **PostgreSQL-spezifisch**: Pruefe JEDE Stelle wo Booleans aus der DB gelesen werden
- **Regression-Check**: Achte besonders darauf ob die Fixes neue Probleme erzeugt haben
- **Keine generischen Hinweise**: Nur konkrete, verifizierbare Bugs mit Datei + Zeilennummer

# Learning-NC — Codex Übergabe-Dokument

> Stand: 2026-03-18 | Version: 2.6.0 | Übergabe von: Claude Code Session

---

## 1. Projekt-Kontext

**Learning-NC** ist eine native Nextcloud-App für Lernkarteien mit Leitner-System, Gamification und Kurs-Management.

- **Tech-Stack**: PHP 8.1+, Vue 2.7, PostgreSQL 16, Nextcloud 29–31
- **App-ID**: `learning`, Namespace: `OCA\Learning`
- **Dev-Server**: learning-dev (.65), Docker `learning-app` (NC:30), `learning-db` (PG:16)
- **Code lokal**: `/home/andre/Workspace/Code/learning-nc/app/`
- **Code im Container**: `/var/www/html/custom_apps/learning/`

---

## 2. Deploy-Workflow

```bash
# PHP-Änderungen
scp app/lib/...Datei.php learning-dev:~/learning-nc/app/lib/.../
ssh learning-dev 'docker cp ~/learning-nc/app/lib/.../Datei.php \
  learning-app:/var/www/html/custom_apps/learning/lib/.../ && \
  docker exec learning-app php -r "opcache_reset();"'

# Vue/JS-Änderungen
scp app/src/components/Datei.vue learning-dev:~/learning-nc/app/src/components/
ssh learning-dev 'cd ~/learning-nc/app && npm run build 2>&1 | tail -3'
ssh learning-dev 'cd ~/learning-nc/app && tar cf /tmp/js-bundle.tar js/ && \
  docker cp /tmp/js-bundle.tar learning-app:/tmp/ && \
  docker exec learning-app bash -c \
    "cd /var/www/html/custom_apps/learning && tar xf /tmp/js-bundle.tar"'
```

**WICHTIG**: Vue-Datei zuerst auf learning-dev kopieren, DANN `npm run build`.

### Verbindlicher Workflow ab 2026-03-19

## 2. Deploy-Workflow (PFLICHT-REIHENFOLGE)

**Schritt 0 — IMMER zuerst Git-Stand sicherstellen:**
```bash
cd ~/learning-nc
git pull origin main   # erst dann Dateien verändern
```

**Schritt 1 — PHP-Änderungen:**
```bash
docker cp ~/learning-nc/app/lib/...Datei.php \
  learning-app:/var/www/html/custom_apps/learning/lib/.../
docker exec learning-app touch /var/www/html/custom_apps/learning/lib/.../Datei.php
docker exec learning-app apache2ctl graceful
```

**Schritt 2 — Vue/JS-Änderungen:**
```bash
cd ~/learning-nc/app && npm run build
tar cf /tmp/js-bundle.tar js/
docker cp /tmp/js-bundle.tar learning-app:/tmp/
docker exec learning-app bash -c \
  "cd /var/www/html/custom_apps/learning && tar xf /tmp/js-bundle.tar"
```

**Schritt 3 — NACH jeder Session: committen + pushen**
```bash
git add -A app/
git commit -m "feat/fix: ..."
git push origin main
```

**NIEMALS:**
- Dateien direkt auf learning-dev erstellen ohne sie danach zu committen
- Eine Session beenden ohne git commit + push
- `git reset --hard` ohne explizite Nutzerfreigabe

**Richtiges Arbeitsmuster:**
1. Code auf der Workstation in `~/Workspace/Code/learning-nc/app/` schreiben
2. `git add` + `git commit` + `git push` auf der Workstation
3. Auf `learning-dev`: `git pull origin main`
4. Deploy in den Container: `docker cp` + `apache2ctl graceful` für PHP oder `npm run build` + JS-Bundle-Tar für Vue/JS

---

## 3. Coding-Standards

### PHP
- `declare(strict_types=1);` am Anfang jeder Datei
- Namespace: `OCA\Learning\...`
- Controller: `@NoAdminRequired` Docblock + `#[UserRateLimit]` Attribut
- DB-Queries: `IQueryBuilder` via `$this->db->getQueryBuilder()`, kein Raw-SQL
- Tabellennamen ohne `oc_`-Prefix in `->from()` — NC QB fügt Prefix automatisch hinzu
- Exceptions: `\RuntimeException` für Business-Logik-Fehler
- Controller gibt immer `new DataResponse($data)` oder `new DataResponse(['error'=>...], Http::STATUS_BAD_REQUEST)` zurück

### Vue
- Vue 2.7 (nicht Vue 3!)
- SFC mit `<template>`, `<script>`, `<style scoped>`
- Imports: `@nextcloud/axios`, `@nextcloud/router` (generateUrl), `@nextcloud/vue`
- NC-Komponenten: `NcButton`, `NcNoteCard`, `NcModal`, `NcLoadingIcon`, `NcEmptyContent`
- Kein TypeScript — reines JS
- Übersetzungen: `t('learning', 'Text')` (globale Funktion, kein Import nötig)
- CSS-Variablen: `var(--color-primary-element)`, `var(--color-main-text)`, `var(--color-border)`, etc.
- CSRF: automatisch via NC-axios
- API-Header: `'OCS-APIREQUEST': 'true'` wird von NC-axios automatisch gesetzt

### Allgemein
- Kein `console.log` in Produktion
- Kein direkter DB-Zugriff ohne QueryBuilder
- Keine Secrets im Code

---

## 4. Datenbankschema (13 Tabellen)

```
oc_learning_pools          — id, name, description, user_id, created_at
oc_learning_questions      — id, pool_id, text, image_path, pbq_subtype, pbq_config
oc_learning_answers        — id, question_id, text, is_correct, position
oc_learning_sessions       — Training-Sessions
oc_learning_user_answers   — Antworten pro Session
oc_learning_leitner_items  — Leitner-Box-State pro User+Frage
oc_learning_pool_shares    — Pool-Sharing zwischen Usern
oc_learning_analytics      — Aggregierte Stats
oc_learning_question_translations  — Übersetzungen
oc_learning_answer_translations
oc_learning_courses        — id, title, description, instructor_uid, status
oc_learning_course_pools   — course_id, pool_id, sort_order, required, question_count
oc_learning_course_members — course_id, user_id, role, enrolled_at

oc_learning_duel_sessions  — Duel-Sessions (neu in v2.6)
oc_learning_duel_answers   — Antworten pro Duel-Runde (neu in v2.6)
```

**Duel-Tabellen-Schema:**
```sql
oc_learning_duel_sessions:
  id, code(varchar6), creator_uid, opponent_uid, pool_id,
  question_ids(text/JSON), status(waiting|ready|active|finished|expired),
  current_question_index, creator_score, opponent_score,
  creator_ready(bool), opponent_ready(bool),
  creator_last_poll(int), opponent_last_poll(int), created_at(int)

oc_learning_duel_answers:
  id, duel_id, question_index, player_uid,
  answer_correct(bool), answered_at(bigint), points_earned(int)
```

---

## 5. Offene Aufgaben (Priorität absteigend)

### 5.1 Duel — Navigation-Umbau für Schüler [PRIO 1]

**Problem**: CourseDetail zeigt für Schüler den Tab "Pools". Stattdessen sollen Schüler direkt die Lernmodi sehen, und die Pool-Auswahl erst nach Modus-Wahl.

**Datei**: `app/src/components/CourseDetail.vue`

**Aktuell (Schüler-Tabs)**:
```js
return [
  { id: 'pools', label: 'Pools' },
  { id: 'my-progress', label: 'Mein Fortschritt' },
  { id: 'leaderboard', label: 'Leaderboard' },
  { id: 'duel', label: 'Duell' },
]
```

**Neu (Schüler-Tabs)**:
```js
return [
  { id: 'training', label: 'Training' },
  { id: 'leitner', label: 'Leitner' },
  { id: 'swipe', label: 'Wahr/Falsch' },
  { id: 'exam', label: 'Exam' },
  { id: 'my-progress', label: 'Mein Fortschritt' },
  { id: 'leaderboard', label: 'Leaderboard' },
  { id: 'duel', label: 'Duell' },
]
```

**Schüler-Flow nach Klick auf Modus-Tab**:
1. Zeige Pool-Auswahl-Liste (`coursePools` — die Pools des Kurses)
2. Schüler klickt Pool → startet den jeweiligen Lernmodus

**Implementierung**:
- Füge in `data()` hinzu: `selectedLearningPool: null, activeLearningMode: null`
- Für jeden Modus-Tab (`training`, `leitner`, `swipe`, `exam`): zeige zunächst eine Pool-Auswahl-Liste
- Nach Pool-Wahl: render die entsprechende Komponente (`TrainingMode`, `LeitnerMode`, `SwipeMode`, `ExamMode`) mit `poolId`
- "Zurück" aus dem Modus → `selectedLearningPool = null` (zurück zur Pool-Auswahl)
- Die Komponenten sind in `App.vue` registriert und müssen auch in `CourseDetail.vue` importiert werden

**Vorhandene Modus-Komponenten** (alle in `app/src/components/`):
- `TrainingMode.vue` — Props: `:poolId`, `:totalQuestions`, `@back`
- `LeitnerMode.vue` — Props: `:poolId`, `@back`
- `SwipeMode.vue` — Props: `:poolId`, `:totalQuestions`, `@back`
- `ExamMode.vue` — Props: `:poolId`, `:totalQuestions`, `@back`

**questionCount für totalQuestions**: Lade via `GET /api/pools/{poolId}/questions` und nimm `response.data.length`

**Lehrer/Admin bleiben unverändert**: `visibleTabs` für `isInstructor` bleibt wie es ist (Pools | Members | Progress | Leaderboard | Duell).

**"Mein Fortschritt"-Tab**: bleibt wie bisher (`currentTab === 'my-progress'` → `<StudentDetail>`). `@back` zeigt jetzt auf `currentTab = 'training'` statt `'pools'`.

---

### 5.2 Duel — Warte-Phase UX verbessern [PRIO 2]

**Problem**: Nach Antwort-Abgabe zeigt das Fenster eine Warte-Overlay ("Warte auf Gegner...") über der Frage. Das wirkt leer/hängt.

**Datei**: `app/src/components/DuelMode.vue`

**Verbesserung**:
- Nach Antwort-Abgabe: die gewählte Antwort visuell hervorheben (z.B. `border-color: var(--color-primary-element)`)
- Das Waiting-Overlay soll semi-transparent über dem Card liegen, Frage bleibt lesbar
- Die abgegebene Antwort (`selectedAnswerId`) soll als "ausgewählt" markiert bleiben (z.B. grüner Border wenn `answeredCorrect`, roter wenn falsch)

Aktuell ist `.waiting-overlay` mit `position: absolute; inset: 0` implementiert — das ist korrekt, nur die Transparenz verbessern.

---

### 5.3 Duel — Feedback-Phase zeigt korrekte Antwort [PRIO 2]

**Status**: Teilweise implementiert. `DuelService::submitAnswer()` gibt `correct_answer_id` und `my_answer_correct` zurück. `DuelMode.vue` speichert `correctAnswerId` und `lastQuestion`.

**Was noch fehlt**:
- Im Feedback bei RICHTIGER Antwort ebenfalls die Antwort anzeigen (aktuell: `v-if="!answeredCorrect"`)
- Optional: alle Antworten mit Grün/Rot markieren

---

### 5.4 Duel — Beide Antworten kommen gleichzeitig an [PRIO 2]

**Edge Case**: Wenn beide Spieler gleichzeitig antworten, bekommt jeder die Response seines eigenen Submits. Polling erkennt den Fortschritt via `current_question_index`.

**Bekanntes Problem**: `lastQuestionIndex` wird auf `-1` gesetzt in `createDuel()`. Nach beiden Antworten springt `current_question_index` von 0 auf 1. Der Check `r.data.current_question_index > this.lastQuestionIndex + 1` ist `1 > 0` = true. Das sollte funktionieren.

**Falls der Transition nicht klappt** (Symptom: bleibt in question-Phase hängen): Prüfe ob `applyStateTransitions` den `indexAdvanced`-Check korrekt ausführt, wenn beide durch Polling synchronisiert werden.

---

### 5.5 App Store Release v2.6.0 [PRIO 3]

**Was rein soll**:
- Duel-Modus (komplett)
- Navigation-Umbau Schüler
- CHANGELOG.md aktualisieren
- `app/appinfo/info.xml` — Version bereits auf `2.6.0`

**Release-Schritte** (in CLAUDE.md dokumentiert):
```bash
ssh learning-dev 'cd ~/learning-nc/app && sudo rm -rf build && sudo mkdir -p build/learning'
ssh learning-dev 'cd ~/learning-nc/app && sudo cp -r appinfo css img js l10n lib templates CHANGELOG.md LICENSE README.md build/learning/'
ssh learning-dev 'cd ~/learning-nc/app && sudo rm -f build/learning/js/*.map'
ssh learning-dev 'cd ~/learning-nc/app/build && sudo tar -czf learning-2.6.0.tar.gz learning'
```

---

## 6. Aktuelle Duel-Implementierung (Stand 2026-03-18)

### Was fertig ist:
- ✅ DB-Tabellen `oc_learning_duel_sessions` + `oc_learning_duel_answers`
- ✅ Migration v2.4, v2.5 (Tabellen), v2.6 (bigint-Fix für answered_at)
- ✅ `DuelSession.php`, `DuelSessionMapper.php`, `DuelAnswer.php`, `DuelAnswerMapper.php`
- ✅ `DuelService.php` — create, join, ready, getState, submitAnswer, rematch
- ✅ `DuelController.php` — alle 6 Endpoints mit @NoAdminRequired + RateLimit
- ✅ routes.php — 6 Duel-Routen eingetragen
- ✅ `DuelMode.vue` — vollständige UI (join, lobby, question, feedback, finished, expired)
- ✅ Pool-Auswahl + Fragenanzahl (5/10/15/20) im Join-Screen
- ✅ Scoring: beide richtig (+3/+2 je nach Speed), einer richtig (+4/0), beide falsch (-1/-1)
- ✅ Tie-Threshold 50ms
- ✅ Timeout-Detection (30s Inaktivität → expired)
- ✅ Rematch
- ✅ Server-seitige Answer-Validierung (Client sendet answerId, Server prüft is_correct)
- ✅ `selectQuestions` filtert PBQ-Fragen (nur Fragen mit Antworten in learning_answers)
- ✅ Duell als Sub-Tab in CourseDetail (für Schüler und Lehrer)

### Was noch nicht funktioniert:
- ⚠️ Warte-Phase nach Antwort wirkt "leer" (UX-Problem, kein Bug)
- ⚠️ Schüler-Navigation: Pools-Tab soll durch Lernmodi ersetzt werden (5.1)

### Bekannte Eigenheiten:
- `coursePools` in CourseDetail verwendet `pool_id`/`pool_name`, DuelMode normalisiert das in `mounted()`
- PBQ-Fragen (pbq_subtype = 'cli'|'dropdown'|'placement'|'cable') haben keine `learning_answers`-Einträge → werden aus Duel-Auswahl gefiltert
- `answered_at` ist bigint (Millisekunden Unix-Timestamp), da JS `Date.now()` ms liefert

---

## 7. Wichtige Dateipfade

```
app/lib/Controller/DuelController.php
app/lib/Service/DuelService.php
app/lib/Db/DuelSession.php
app/lib/Db/DuelSessionMapper.php
app/lib/Db/DuelAnswer.php
app/lib/Db/DuelAnswerMapper.php
app/lib/Migration/Version002400Date20260318000000.php   (duel_sessions Tabelle)
app/lib/Migration/Version002500Date20260318000000.php   (duel_answers Tabelle)
app/lib/Migration/Version002600Date20260318000000.php   (answered_at bigint)
app/src/components/DuelMode.vue
app/src/components/CourseDetail.vue   ← Hauptziel für Schüler-Nav-Umbau
app/src/App.vue
app/appinfo/routes.php
```

---

## 8. Test-Credentials (Dev-Server)

- URL: `http://192.168.178.65:8080` (NICHT learning-dev als Hostname — nicht auflösbar)
- Admin-User: `admin` / `admin`
- Test-User: `testuser2` / `test123`
- Pool für Duel-Tests: Pool-ID `81` (Network+ DE, 275 Fragen, mit Antworten)

**Duel manuell testen** (curl):
```bash
# Duel erstellen (als admin)
curl -u admin:admin -X POST http://192.168.178.65:8080/apps/learning/api/duels \
  -H "OCS-APIREQUEST: true" -H "Content-Type: application/json" \
  -d '{"poolId":81,"numQuestions":3}'

# Beitreten (als testuser2) — CODE aus obiger Response
curl -u testuser2:test123 -X POST http://192.168.178.65:8080/apps/learning/api/duels/CODE/join \
  -H "OCS-APIREQUEST: true" -H "Content-Type: application/json" -d '{}'

# Beide ready
curl -u admin:admin -X POST http://192.168.178.65:8080/apps/learning/api/duels/CODE/ready \
  -H "OCS-APIREQUEST: true" -H "Content-Type: application/json" -d '{}'
curl -u testuser2:test123 -X POST http://192.168.178.65:8080/apps/learning/api/duels/CODE/ready \
  -H "OCS-APIREQUEST: true" -H "Content-Type: application/json" -d '{}'

# State + Frage abrufen
curl -u admin:admin http://192.168.178.65:8080/apps/learning/api/duels/CODE/state \
  -H "OCS-APIREQUEST: true"

# Antwort submitten (answerId aus state.current_question.answers[x].id)
curl -u admin:admin -X POST http://192.168.178.65:8080/apps/learning/api/duels/CODE/answer \
  -H "OCS-APIREQUEST: true" -H "Content-Type: application/json" \
  -d '{"answerId":12345,"answeredAt":1773838215663}'
```

---

## 10. Feature-Idee: Duell-Liga [NICHT YET IMPLEMENTIERT]

> Idee des Projektinhabers, noch kein Code vorhanden. Hier zur Planung dokumentiert.

### Konzept

Ein **Ligasystem** auf Kurs-Ebene, bei dem alle Kursteilnehmer in einer Saison Duelle gegeneinander austragen. Jeder gegen jeden, nach festen Regeln. Mit Ligatabelle und einer "Champions League"-Stufe für die Besten.

### Spielprinzip

**Reguläre Liga:**
- Pro Saison (z.B. 1 Woche oder 2 Wochen) kann jeder Spieler gegen jeden anderen Spieler im Kurs ein Liga-Duell spielen
- Feste Regeln pro Saison: gleicher Pool, gleiche Fragenanzahl (z.B. 10), gleiche Scoring-Regeln wie normales Duell
- Ein Duell pro Paarung pro Saison (A vs. B → ein Ergebnis, nicht mehrfach)
- Liga-Duelle laufen über denselben Duel-Mechanismus (kurzer Code oder direktes Herausfordern per Username)

**Ligatabelle** (wie Fußball-Bundesliga):
- Spalten: Platz | Spieler | Gespielt | Siege | Niederlagen | Punkte | Tordifferenz (= Punktedifferenz aus Duellen)
- 3 Punkte für Sieg, 1 Punkt bei Unentschieden, 0 bei Niederlage
- Tiebreaker: Punktedifferenz aus allen Duellen

**Champions League (Aufstieg):**
- Top N Spieler der Liga (z.B. Top 4 oder Top 8) spielen in der Champions League-Runde
- Schwerere Fragen: anderer Pool (z.B. Premium/Szenario-Pool des Kurses) oder mehr Fragen pro Duell
- KO-System oder eigene Mini-Tabelle
- Besonderes Badge/Trophy für CL-Teilnehmer und Sieger

### Datenmodell (Vorschlag)

```sql
-- Eine Liga-Saison pro Kurs
oc_learning_league_seasons:
  id, course_id, name (varchar), pool_id, num_questions(int, default 10),
  status (open|active|finished), started_at(int), ends_at(int), created_at(int)

-- Ergebnisse aller Liga-Duelle
oc_learning_league_results:
  id, season_id, duel_session_id (FK → oc_learning_duel_sessions),
  player_a_uid, player_b_uid,
  score_a(int), score_b(int),
  league_points_a(int), league_points_b(int),   -- 3/1/0
  played_at(int)

-- Champions-League-Runde (optional separate Tabelle)
oc_learning_league_cl_rounds:
  id, season_id, round_number(int), player_a_uid, player_b_uid,
  duel_session_id, winner_uid, played_at(int)
```

### API-Endpoints (Vorschlag)

```
POST /api/courses/{courseId}/leagues              → Saison erstellen (Instructor)
GET  /api/courses/{courseId}/leagues/active       → Aktive Saison + Tabelle
POST /api/courses/{courseId}/leagues/{id}/start   → Saison starten
POST /api/courses/{courseId}/leagues/{id}/finish  → Saison abschließen + CL-Runde auslösen
POST /api/courses/{courseId}/leagues/{id}/challenge/{opponentUid}  → Gegner herausfordern
GET  /api/courses/{courseId}/leagues/{id}/table   → Ligatabelle (Standings)
GET  /api/courses/{courseId}/leagues/{id}/cl      → Champions-League-Bracket
```

### UI (Vorschlag)

- Neuer Tab in CourseDetail: **"Liga"** (für Schüler und Lehrer)
- Schüler sieht: Ligatabelle + "Gegner herausfordern"-Button pro Zeile + eigene offene Duelle
- Lehrer sieht zusätzlich: Saison erstellen/starten/beenden, Pool wählen
- Champions-League-Bracket wenn CL-Runde aktiv

### Abhängigkeiten

- Baut auf dem fertigen Duel-System auf (`oc_learning_duel_sessions`, `DuelService`)
- Ein Liga-Duell ist ein normales Duell mit `liga_season_id`-Referenz
- `DuelService::createDuel()` bekommt optionalen `leagueSeasonId`-Parameter
- Nach Duel-Abschluss: `LeagueService::recordResult()` wird aufgerufen

### Design-Entscheidungen

**1. Saisondauer: 2 Wochen, manuell startbar durch Lehrer**
- 2 Wochen = genug Zeit damit alle Spieler ihre Duelle absolvieren können, ohne dass es einschläft
- Lehrer startet die Saison manuell (z.B. zu Kursstart) — kein Auto-Start
- `ends_at = started_at + 14 * 86400`
- Minimum 4 Teilnehmer um eine Saison starten zu können

**2. Herausfordern: Direkt per Klick aus der Ligatabelle**
- Jede Zeile in der Tabelle hat einen "Herausfordern"-Button (nur sichtbar wenn das Duell noch nicht gespielt wurde)
- Kein Code-Sharing nötig — das System erstellt das Duell direkt und der Gegner sieht es beim nächsten Seitenaufruf als "offenes Liga-Duell"
- Ausstehende Liga-Duelle erscheinen oben in der Liga-Ansicht als "Offene Herausforderungen" mit Accept-Button

**3. Forfeit: 5 Tage Timeout, dann auto-Niederlage für den Untätigen**
- Wer herausgefordert wird hat 5 Tage Zeit anzunehmen
- Wer angenommen hat hat 5 Tage Zeit das Duell tatsächlich zu spielen
- Danach: Forfeit — Herausforderer bekommt 3 Punkte, kein Duell wird gespielt
- Cron-Job oder Lazy-Evaluation beim nächsten `GET /table`-Aufruf

**4. Champions League: Top 4, KO-System (Halbfinale + Finale)**
- Feste Slots: Top 4 der Liga-Tabelle nach Saisonende
- Halbfinale: Platz 1 vs. Platz 4, Platz 2 vs. Platz 3
- Finale: die beiden Halbfinal-Sieger
- Schwererer Pool: der Lehrer wählt beim Saison-Erstellen einen "CL-Pool" (z.B. Szenario-/Premium-Pool), 15 Fragen statt 10
- Bei < 4 aktiven Spielern (mind. 1 Duell gespielt): keine CL-Runde, Saison endet nach Liga

**5. Scope: strikt pro Kurs**
- Jede Liga gehört zu genau einem Kurs — macht inhaltlich Sinn (gleicher Lernstoff)
- Ein Kurs kann mehrere aufeinanderfolgende Saisons haben (Saison 1, 2, 3...)
- Kursübergreifende Liga = spätere Erweiterung, nicht im v1

---

## 9. Hinweise für Codex

1. **Vue-Dateien immer zuerst auf learning-dev kopieren, DANN build** — sonst ist die Änderung nicht im Bundle
2. **Nach PHP-Änderungen opcache_reset()** nicht vergessen
3. **Keine neuen DB-Migrationen ohne Versionsnummer-Schema** `VersionXXXXDate{YYYYMMDD}000000`
4. **PBQ-Fragen** (`pbq_subtype IS NOT NULL`) haben spezielle Behandlung — nicht in regulären Lernmodi ändern
5. **NC QueryBuilder**: `->from('learning_foo')` ohne `oc_`-Prefix — der Prefix wird automatisch hinzugefügt
6. **Rate-Limits**: `#[UserRateLimit(limit: N, period: 60)]` auf alle öffentlichen Endpoints
7. **Schüler vs. Lehrer**: `$userRole` / `isInstructor` immer prüfen — Schüler dürfen keine Admin-Aktionen
8. **CSRF**: automatisch durch NC-axios, kein manuelles Token nötig

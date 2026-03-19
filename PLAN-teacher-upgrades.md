# Plan: Dozenten-Aufwertung — Gesamtüberblick

Stand: 2026-03-19
Basis: Analyse aller didaktischer Hebel nach v2.6.0

---

## Übergeordnetes Ziel

Learning-NC soll für Dozenten von einem "Pool-Verwaltungs-Tool" zu einem
**echten Unterrichtssteuerungs-Cockpit** werden. Der Dozent soll nicht nur
Pools zuweisen können, sondern aktiv steuern, was wann gelernt wird — und
sofort sehen, wo die Klasse steht und scheitert.

---

## Priorisierung (Gesamtbild)

| # | Feature-Block | Impact | Aufwand | Wann |
|---|--------------|--------|---------|------|
| 1 | Curriculum-Scope (Kapitel-Filter) | ★★★★★ | M | Sofort → eigener Plan |
| 2 | Kapitel-Heatmap + Fehlerschwerpunkte | ★★★★★ | M | Kurzfristig |
| 3 | Häufig-falsch-Fragen + Schnellkorrektur | ★★★★☆ | S | Kurzfristig |
| 4 | Modus-/Freigabesteuerung pro Kurs | ★★★★☆ | M | Mittelfristig |
| 5 | Dozenten-Dashboard Unterrichtssignale | ★★★★☆ | L | Mittelfristig |
| 6 | Live-Exam-Slot / Mock-Exam | ★★★☆☆ | M | Mittelfristig |
| 7 | Kursankündigungen / Automatische Hinweise | ★★★☆☆ | M | Mittelfristig |
| 8 | Bewertung / Noten-Nähe | ★★★☆☆ | L | Später |
| 9 | Didaktische Fragenqualität (Tagging) | ★★★☆☆ | S | Später |

---

## Block 1: Curriculum-Scope (Kapitel-Filter)

→ **Separater detaillierter Plan**: `PLAN-course-chapter-curriculum-filter.md`

**Kurzfassung:**
- Dozent wählt aktive Kapitel pro Kurs aus einer Checkbox-Liste
- neue Tabellen `learning_course_curriculum_scopes` + `_scope_chapters`
- Schüler können im Kurs-Modus zwischen "Alle Fragen" und "Aktuelle Kursthemen" wechseln
- Duel im Kurskontext nutzt standardmäßig den aktiven Scope

**Warum zuerst:**
Alle anderen Features (Heatmap, Freigabe, Mock-Exam) profitieren vom gleichen
Kapitel-Konzept. Wer Scope früh baut, hat die Daten für alles andere.

---

## Block 2: Kapitel-Heatmap + Fehlerschwerpunkte

### Ziel

Der Dozent sieht auf einen Blick: **Wo hängt die Klasse?**

Heute gibt es Schüler-Progress pro Person und globale XP-Zahlen.
Was fehlt: eine aggregierte, kapitelbasierte Sicht auf Fehlerquoten.

### Produkt-Idee

Im Dozenten-Bereich von `CourseDetail.vue` neuer Tab oder Abschnitt **"Klasse"**:

```
Kapitel 1 — Grundlagen TCP/IP       ████████░░  78% Erfolg   38 Lernende
Kapitel 2 — Subnetting              ████░░░░░░  42% Erfolg   35 Lernende  ← Warnung
Kapitel 3 — Routing Protocols       ██████░░░░  61% Erfolg   30 Lernende
```

- Farb-Codierung: grün/gelb/rot nach Schwellenwert
- Sortierung nach Fehlerquote (schlechteste oben)
- Drill-down: welche Fragen in Kapitel 2 sind am schlechtesten?

### Datengrundlage

Tabelle `oc_learning_user_answers` enthält bereits:
- `question_id`, `user_id`, `correct` (bool), `created_at`

Verknüpft mit `oc_learning_questions.chapter_key` und Kurs-Membern ist alles da.

### Backend

**Neuer Service-Endpunkt in `CourseService`:**

```php
getChapterHeatmap(int $courseId, string $userId): array
```

Liefert pro `chapter_key`:
- `chapter_title`, `chapter_order`
- `total_answers`, `correct_answers`, `success_rate`
- `unique_learners` (User die dieses Kapitel überhaupt beantwortet haben)
- `top_wrong_questions` (5 schlechteste Fragen im Kapitel)

**SQL-Kern (PostgreSQL):**

```sql
SELECT
    q.chapter_key,
    q.chapter_title,
    q.chapter_order,
    COUNT(ua.id) AS total_answers,
    SUM(CASE WHEN ua.correct THEN 1 ELSE 0 END) AS correct_answers,
    COUNT(DISTINCT ua.user_id) AS unique_learners
FROM oc_learning_user_answers ua
JOIN oc_learning_questions q ON ua.question_id = q.id
JOIN oc_learning_course_pools cp ON q.pool_id = cp.pool_id
JOIN oc_learning_course_members cm ON cm.course_id = cp.course_id AND cm.user_id = ua.user_id
WHERE cp.course_id = :courseId
  AND cm.role IN ('student', 'co_instructor')
GROUP BY q.chapter_key, q.chapter_title, q.chapter_order
ORDER BY (SUM(CASE WHEN ua.correct THEN 1 ELSE 0 END)::float / NULLIF(COUNT(ua.id), 0)) ASC
```

**Neuer API-Endpunkt:**

```
GET /api/courses/{courseId}/chapter-heatmap
```

→ Nur für Instructor/Co-Instructor zugänglich (RoleService check).

**Route in `routes.php`:**

```php
['name' => 'course#chapterHeatmap', 'url' => '/api/courses/{courseId}/chapter-heatmap', 'verb' => 'GET'],
```

### Frontend

Neuer Unter-Abschnitt im Dozenten-Tab "Klasse" in `CourseDetail.vue`:

- Balken-Diagramm mit CSS (kein Chart.js-Overhead)
- Ampelfarbe je Kapitel
- Klick auf Kapitel → expandierter Bereich mit den 5 schlechtesten Fragen
- Schwellenwerte: <50% = rot, <70% = gelb, ≥70% = grün

### Knackpunkte

- Fragen ohne `chapter_key` → Gruppe "Kein Kapitel" separat
- Performance: Bei großen Kursen ggf. gecacht (ICacheFactory, 15min TTL)
- Zeitraum-Filter (letzte 7/30 Tage vs. gesamt) für V2

---

## Block 3: Häufig-falsch-Fragen + Schnellkorrektur

### Ziel

Dozent sieht die Top-10 schlechtesten Fragen im Kurs und kann sie direkt
korrigieren oder deaktivieren — ohne den ganzen Pool-Editor öffnen zu müssen.

### Produkt-Idee

Tab oder Abschnitt **"Schwache Fragen"** im Dozenten-Bereich:

```
#  Frage                              Kapitel      Fehler%   Aktionen
1. "Was ist die MTU bei Ethernet?"   Kap. 1        87%       [Bearbeiten] [Pausieren]
2. "Berechne Subnetz /27"            Kap. 2        82%       [Bearbeiten] [Pausieren]
```

**"Pausieren"** = Frage im Kurskontext temporär nicht ziehen (kein Löschen).

### Backend

**Erweiterung von `CourseService`:**

```php
getWeakQuestions(int $courseId, string $userId, int $limit = 10): array
```

Liefert:
- `question_id`, `question_text` (gekürzt), `chapter_key`, `chapter_title`
- `total_answers`, `wrong_rate`
- `is_paused_in_course` (neues Flag)

**Neues DB-Feature: Frage-Pause im Kurskontext**

Nicht im globalen Pool pausieren, sondern nur für diesen Kurs.
Neue Spalte in `oc_learning_course_pools` reicht nicht (granularität: Frage-Ebene).

Empfehlung: Neue Tabelle `oc_learning_course_question_overrides`:

```sql
CREATE TABLE oc_learning_course_question_overrides (
    id SERIAL PRIMARY KEY,
    course_id INTEGER NOT NULL,
    question_id INTEGER NOT NULL,
    paused BOOLEAN NOT NULL DEFAULT FALSE,
    highlight BOOLEAN NOT NULL DEFAULT FALSE,  -- für Block 4: "heute besonders wichtig"
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    UNIQUE (course_id, question_id)
);
```

**Neue API-Endpunkte:**

```
GET  /api/courses/{courseId}/weak-questions
POST /api/courses/{courseId}/questions/{questionId}/override
     body: { paused: bool, highlight: bool }
```

**In `resolveCoursePoolContext()`:** paused Fragen aus der Frage-ID-Liste herausfiltern.

### Frontend

Einfache Tabelle in `CourseDetail.vue` mit:
- Sortierung nach Fehlerquote
- NcButton "Pausieren" → sofortiges Update via API
- NcButton "Bearbeiten" → öffnet `QuestionForm.vue` im Edit-Mode

---

## Block 4: Modus-/Freigabesteuerung pro Kurs

### Ziel

Dozent kann pro Kurs steuern:
- Welche Lernmodi sind verfügbar (Duel, Liga, Exam, Leitner, Training)
- Ab wann ist Exam freigeschaltet (Zeitfenster)
- Wie viele Exam-Versuche pro Schüler/Tag

### Produkt-Idee

Neuer Dozenten-Abschnitt **"Kursregeln"** in `CourseDetail.vue`:

```
Lernmodi
☑ Training     ☑ Leitner     ☑ Wahr/Falsch
☑ Exam         ☑ Duel        ☐ Liga (deaktiviert)

Exam-Kontrolle
Freigabe: [ab sofort ▼]   Versuche/Tag: [3]   Cooldown: [24h]

Pflichtbedingung
☑ Training muss vor Exam abgeschlossen sein (mind. 1× jeden Pool)
```

### Backend

**Neue Spalten in `oc_learning_courses`** (Migration):

```sql
ALTER TABLE oc_learning_courses ADD COLUMN
    mode_config JSONB DEFAULT '{"training":true,"leitner":true,"swipe":true,"exam":true,"duel":true,"league":true}';
ALTER TABLE oc_learning_courses ADD COLUMN
    exam_available_from TIMESTAMP NULL;
ALTER TABLE oc_learning_courses ADD COLUMN
    exam_attempts_per_day INTEGER NULL;
ALTER TABLE oc_learning_courses ADD COLUMN
    exam_requires_training BOOLEAN DEFAULT FALSE;
```

**Erweiterung `CourseService::findById()`:**
- liefert `mode_config`, `exam_rules` für Schüler-UX
- prüft Zugriffsbedingungen beim Exam-Start

**Schüler-Seite:**
- `CourseDetail.vue` blendet deaktivierte Modi-Buttons aus
- `ExamMode.vue` prüft `exam_available_from` und zeigt Countdown falls nötig

### Knackpunkte

- `exam_requires_training`: braucht Check in `CourseService` ob Schüler alle
  Pflicht-Fragen mindestens einmal richtig beantwortet hat
- JSON-Spalte statt Einzel-Spalten für Modi ist einfacher zu erweitern

---

## Block 5: Dozenten-Dashboard — Unterrichtssignale

### Ziel

Statt Rohdaten (XP-Zahlen, Session-Count) bekommt der Dozent **echte
Unterrichtssignale**:

- Wer ist aktiv / inaktiv?
- Wer hängt bei welchem Thema?
- Was hat sich seit letzter Woche verändert?

### Produkt-Idee

Neuer Tab **"Dashboard"** in `CourseDetail.vue` (Dozenten-only):

```
Aktivität (letzte 7 Tage)
  Aktive Schüler:  18 / 24    [████████████████░░░░ 75%]
  Neue Antworten:  1.247
  Ø Erfolgsrate:   64%  (↑ +3% vs. Vorwoche)

Frühwarnungen  ⚠
  ● Alexander M.   — 12 Tage inaktiv
  ● Emma K.        — Kapitel 2 < 40% nach 5 Versuchen
  ● 3 weitere Schüler...

Klassen-Kapitel-Heatmap (→ Block 2)

Letzte Schüler-Aktivitäten
  14:22  sophie hat Exam abgeschlossen  78%
  13:45  luca hat Duel gestartet
  12:01  finn hat Kapitel 3 gestartet
```

### Backend

**`CourseService::getDashboard(int $courseId, string $userId): array`**

Liefert:
- `activity_7d` — unique User mit min. 1 Session in 7 Tagen
- `total_members` — Schüler-Count
- `new_answers_7d`, `avg_success_rate`, `success_rate_delta` (vs. Vorwoche)
- `warnings` — Liste von At-Risk-Signals (inaktiv, stuck, …)
- `recent_activity` — letzte 20 Events (Exam, Duel-Start, Kapitel-Beginn)

**Frühwarnsystem-Logik:**

```php
// Inaktiv: kein Login seit X Tagen
// Feststeckend: > 5 Versuche in Kapitel, < 40% Erfolg
// Exam-Versagen: 2x Exam < 50%
private function buildWarnings(int $courseId, array $members): array
```

**API:**

```
GET /api/courses/{courseId}/dashboard
```

### Frontend

Neue Komponente `CourseDashboard.vue` (wird in `CourseDetail.vue` per Tab eingebunden).

Aufbau:
- Stats-Cards (CSS Grid, kein Chart.js nötig)
- Heatmap-Block (aus Block 2 wieder verwenden)
- Warnungs-Liste mit NcAvatar + Kontext
- Activity-Feed (einfache Timeline)

---

## Block 6: Live-Exam-Slot / Mock-Exam

### Ziel

Dozent kann einen **zeitlich begrenzten Exam-Slot** für alle Kursteilnehmer
starten — ähnlich wie eine echte Prüfungsklasse.

### Produkt-Idee

Button in Dozenten-Bereich:

```
[Prüfung starten]
Dauer: [90 min] Kapitel: [aktiver Scope ▼] Fragen: [40]
```

Schüler sehen im Kurs einen Banner:
```
⚠ Laufende Prüfung — noch 67 Minuten
[Jetzt teilnehmen]
```

### Backend

**Neue Tabelle `oc_learning_course_exam_slots`:**

```sql
id, course_id, instructor_id,
started_at, duration_minutes, ends_at,
scope_mode (all/curriculum), question_ids_json,
status (active/closed)
```

**Exam-Slot API:**

```
POST /api/courses/{courseId}/exam-slot        — Dozent startet
GET  /api/courses/{courseId}/exam-slot/active — Schüler prüft ob Prüfung läuft
POST /api/courses/{courseId}/exam-slot/close  — Dozent schließt
```

**`ExamMode.vue`-Erweiterung:**
- prüft beim Starten, ob aktiver Slot existiert
- Wenn ja: Countdown-Timer sichtbar, Fragen aus Slot geladen
- Wenn nein: normaler freier Exam

### Knackpunkte

- Timer-Synchronisation: Countdown im Frontend, Backend prüft `ends_at` bei Submission
- Kein Echtzeit-Push nötig: Polling alle 60s auf `/exam-slot/active` genügt

---

## Block 7: Kursankündigungen + Automatische Hinweise

### Ziel

Dozent kann Nachrichten an Kursteilnehmer schicken, ohne externe Kanäle
(Mattermost, Mail) nutzen zu müssen. Automatische Trigger für Standard-Hinweise.

### Manuelle Ankündigungen

**Neue Tabelle `oc_learning_course_announcements`:**

```sql
id, course_id, instructor_id,
title, body, created_at, expires_at
```

Schüler sehen Ankündigungen als `NcNoteCard` oben in `CourseDetail.vue`.
Dozent kann Ankündigungen erstellen/löschen.

**API:**

```
GET  /api/courses/{courseId}/announcements
POST /api/courses/{courseId}/announcements
DEL  /api/courses/{courseId}/announcements/{id}
```

### Automatische Hinweise (via Nextcloud Notifications)

Bestehende `INotificationManager`-Integration ausbauen:

| Trigger | Empfänger | Nachricht |
|---------|-----------|-----------|
| Dozent aktiviert Kapitel im Scope | alle Schüler | "Kapitel 4 ist jetzt Prüfungsthema" |
| Pflicht-Pool noch nicht abgeschlossen (3 Tage vor Exam-Slot) | betroffene Schüler | "Bitte Pool X abschließen" |
| Exam-Slot gestartet | alle Schüler | "Prüfung läuft — noch 90 Minuten" |
| Schüler wurde zum Duel herausgefordert | Herausgeforderter | (schon vorhanden) |

Die Notification-Logik gehört in `CourseService` und nutzt
`\OCP\Notification\IManager` (wie `NotificationService` für Gamification).

---

## Block 8: Bewertung / Noten-Nähe

### Ziel

Aus Kurs-Aktivität eine **Teilnahme-Aussage** ableiten, ohne ein vollständiges
Notensystem zu bauen.

### Produkt-Idee (minimal, kursinternes Scoring)

Neue Spalte `score_config JSONB` in `oc_learning_courses`:

```json
{
  "training_weight": 0.1,
  "leitner_mastery_weight": 0.4,
  "exam_weight": 0.5,
  "participation_threshold": 0.7
}
```

**`CourseService::getStudentScore(int $courseId, string $studentId): array`**

Liefert:
- `score` (0-100)
- `participation_met` (bool, score ≥ threshold)
- `breakdown` — {training: X, leitner: X, exam: X}

**Dozenten-Ansicht:**

In Mitglieder-Liste neue Spalte "Score" / "Teilnahme ✓/✗".

**Export:** CSV per `/api/courses/{courseId}/export/scores`.

### Knackpunkte

- Exam-Scores bereits vorhanden (`ExamMode.vue` speichert Ergebnis in `user_answers`)
- Leitner-Mastery (Box-5-Count) schon in `leitner_items`
- Training-Participation: Anzahl beantwortete Fragen im Pool / gesamt

---

## Block 9: Didaktische Fragenqualität

### Ziel

Dozent kann Fragen mit didaktischen Tags versehen — unabhängig von
`chapter_key`/`exam_key` (die sind eher curricular, nicht methodisch).

### Tags (V1)

- `basic` — Grundwissen, Recall
- `exam_relevant` — Prüfungsthema
- `transfer` — Anwendung in neuem Kontext
- `scenario` — PBQ/Szenario-Format
- `disputed` — Frage ist unklar/fehlerhaft gemeldet

**Backend:** neue Spalte `didactic_tags TEXT[]` in `oc_learning_questions` (PostgreSQL Array).

**Frontend:** Chip-Tags in `QuestionForm.vue`, filterbar in Pool-Übersicht.

**Dozenten-Nutzen:**
- Mock-Exam gezielt aus `exam_relevant`-Fragen generieren
- `disputed`-Tag als Schnellkorrektur-Signal (→ Block 3)

---

## Implementierungsreihenfolge

### Phase 1 — Sofort (nächste 2 Wochen)

1. **Curriculum-Scope** (→ `PLAN-course-chapter-curriculum-filter.md`)
2. **Kapitel-Heatmap** (Block 2) — baut auf Scope-Daten auf
3. **Schwache Fragen + Pausieren** (Block 3) — `course_question_overrides`-Tabelle

### Phase 2 — Kurzfristig (danach)

4. **Modus-/Freigabesteuerung** (Block 4) — Migration + CourseRules-UI
5. **Dozenten-Dashboard** (Block 5) — composiert Blöcke 2+3+bestehende Daten

### Phase 3 — Mittelfristig

6. **Live-Exam-Slot** (Block 6)
7. **Kursankündigungen** (Block 7)

### Phase 4 — Später / optional

8. **Kursinternes Scoring** (Block 8)
9. **Didaktisches Fragen-Tagging** (Block 9)

---

## Geteilte Infrastruktur (einmal bauen, überall nutzen)

Folgendes lohnt sich, zentral zu bauen:

| Was | Wo | Genutzt von |
|-----|----|-------------|
| `course_question_overrides`-Tabelle | Migration | Block 3, 4, 9 |
| `getChapterHeatmap()` Service-Methode | CourseService | Block 2, 5 |
| `getStudentScore()` Service-Methode | CourseService | Block 5, 8 |
| `CourseDashboard.vue` | src/components/ | Block 5 (composiert alle) |
| Notification-Trigger in CourseService | CourseService | Block 7 |
| Curriculum-Scope in `resolveCoursePoolContext()` | CourseService | Block 1, 2, 4, 6 |

---

## Neue DB-Tabellen (Gesamtbild)

| Tabelle | Block | Zweck |
|---------|-------|-------|
| `oc_learning_course_curriculum_scopes` | 1 | Kursweiter Scope-Container |
| `oc_learning_course_curriculum_scope_chapters` | 1 | Aktive Kapitel im Scope |
| `oc_learning_course_question_overrides` | 3 | Pausieren/Hervorheben pro Frage×Kurs |
| `oc_learning_course_announcements` | 7 | Dozenten-Ankündigungen |
| `oc_learning_course_exam_slots` | 6 | Gesteuerte Prüfungs-Slots |

Neue Spalten:
- `oc_learning_courses.mode_config JSONB` (Block 4)
- `oc_learning_courses.exam_available_from TIMESTAMP` (Block 4)
- `oc_learning_courses.exam_attempts_per_day INTEGER` (Block 4)
- `oc_learning_courses.exam_requires_training BOOLEAN` (Block 4)
- `oc_learning_courses.score_config JSONB` (Block 8)
- `oc_learning_questions.didactic_tags TEXT[]` (Block 9)

---

## Neue API-Endpunkte (Gesamtbild)

```
GET  /api/courses/{id}/chapter-heatmap          Block 2
GET  /api/courses/{id}/weak-questions            Block 3
POST /api/courses/{id}/questions/{qId}/override  Block 3+4
GET  /api/courses/{id}/dashboard                 Block 5
GET  /api/courses/{id}/curriculum-scope          Block 1
PUT  /api/courses/{id}/curriculum-scope          Block 1
GET  /api/courses/{id}/announcements             Block 7
POST /api/courses/{id}/announcements             Block 7
DEL  /api/courses/{id}/announcements/{aid}       Block 7
POST /api/courses/{id}/exam-slot                 Block 6
GET  /api/courses/{id}/exam-slot/active          Block 6
POST /api/courses/{id}/exam-slot/close           Block 6
GET  /api/courses/{id}/export/scores             Block 8
```

---

## Risiken

1. **Performance:** Heatmap-Query über viele Antworten kann langsam werden.
   → Caching mit ICacheFactory (15min TTL), ggf. materialisierte View für große Kurse.

2. **chapter_key-Qualität:** Alle Kapitel-Features stehen und fallen mit
   gepflegten Metadaten in Fragen. Ohne `chapter_key` bleibt Heatmap leer.
   → Hinweis in Dozenten-UI wenn Fragen ohne Kapitel gefunden werden.

3. **Modus-Deaktivierung als Breaking Change:** Wenn Duel global immer geht,
   aber pro Kurs deaktivierbar ist — braucht `CourseDetail.vue` einen Guard
   vor allen Mode-Buttons.

4. **Scoring-Erwartungen:** Kursinternes Scoring darf NICHT wie Noten aussehen
   (NC ist kein LMS-Notensystem). Sprachlich "Teilnahme erfüllt" statt "Note 2".

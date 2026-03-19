# Plan: Kursweite Kapitel-/Themensteuerung fuer Dozenten

Stand: 2026-03-19

## Ziel

Dozenten sollen im Kursbereich gezielt steuern koennen, **welche Kapitel/Themen aus dem Handbuch aktuell gelernt werden**. Diese Auswahl soll auf den bereits vorhandenen Frage-Metadaten (`handbook_key`, `exam_key`, `chapter_key`, `chapter_title`, `chapter_order`) aufsetzen.

Gewuenschter Produkt-Flow:

- Im Dozentenbereich eines Kurses gibt es eine Auswahl nach **Kapitel/Themen aus dem Buch**.
- Der Dozent kann Kapitel **aktivieren/deaktivieren**.
- Wenn Schueler **Kurs-Duelle** spielen, werden standardmaessig nur Fragen geladen, die in dieser aktiven Themenauswahl liegen.
- In anderen Kurs-Lernmodi (`Training`, `Leitner`, `Wahr/Falsch`, `Exam`) koennen Schueler diese Themenauswahl **bewusst zuschalten**, statt immer den gesamten Pool zu laden.

Wichtig: Bestehende Kurse sollen **nicht stillschweigend ihr Verhalten aendern**. Das Feature muss opt-in bleiben.

---

## Ist-Zustand im Code

### Schon vorhanden

- Frage-Metadaten existieren bereits:
  - [app/lib/Db/Question.php](app/lib/Db/Question.php)
  - Import-/Pflegepfad in [app/lib/Controller/ImportController.php](app/lib/Controller/ImportController.php), [app/src/components/QuestionForm.vue](app/src/components/QuestionForm.vue)
- Kurs-Pools haben bereits optionale, **statische Einzel-Filter**:
  - `filter_exam_key`
  - `filter_chapter_key`
  - `filter_question_ids`
  - Modell: [app/lib/Db/CoursePool.php](app/lib/Db/CoursePool.php)
  - Service: [app/lib/Service/CourseService.php](app/lib/Service/CourseService.php)
  - UI: [app/src/components/CourseDetail.vue](app/src/components/CourseDetail.vue)
- Alle relevanten Lernmodi laufen im Kurskontext bereits ueber `courseId`:
  - [app/src/components/TrainingMode.vue](app/src/components/TrainingMode.vue)
  - [app/src/components/LeitnerMode.vue](app/src/components/LeitnerMode.vue)
  - [app/src/components/SwipeMode.vue](app/src/components/SwipeMode.vue)
  - [app/src/components/ExamMode.vue](app/src/components/ExamMode.vue)
  - [app/src/components/DuelMode.vue](app/src/components/DuelMode.vue)
- Backend-seitig wird der Kurskontext schon zentral aufgeloest:
  - [app/lib/Service/CourseService.php](app/lib/Service/CourseService.php)
  - Methode `resolveCoursePoolContext(...)`
- Duel-, Training- und Leitner-Services benutzen diese zentrale Frage-ID-Aufloesung bereits:
  - [app/lib/Service/TrainingService.php](app/lib/Service/TrainingService.php)
  - [app/lib/Service/LeitnerService.php](app/lib/Service/LeitnerService.php)
  - [app/lib/Service/DuelService.php](app/lib/Service/DuelService.php)

### Noch nicht vorhanden

- Keine **kursweite Mehrfachauswahl** von Kapiteln.
- Keine Trennung zwischen:
  - `Pool-Regel des Dozenten` und
  - `temporare aktuelle Themenauswahl des Kurses`
- Keine Schueler-UI, um in Kursmodi gezielt zwischen
  - `gesamtem Kurs-Pool`
  - `aktueller Themenauswahl des Dozenten`
  zu wechseln.
- Duel im Kurs kennt aktuell nur `courseId + poolId`, aber keinen extra Scope wie `nur aktuelle Kapitel`.

---

## Problem mit dem aktuellen Modell

Das heutige Pool-Regelmodell ist **zu statisch und zu eng** fuer den gewuenschten Anwendungsfall:

- `filter_chapter_key` ist nur **ein einzelnes Kapitel**
- er haengt direkt an einem **CoursePool**
- er ist eher als technischer Pool-Zuschnitt gedacht
- er ist nicht gut geeignet als **laufende Unterrichts-/Kapitelsteuerung**

Wenn man das neue Feature einfach auf `filter_chapter_key` quetscht, entstehen sofort Probleme:

- keine Mehrfachauswahl von Kapiteln
- kein sauberer Unterschied zwischen `permanentem Pool-Zuschnitt` und `momentanem Unterrichtsfokus`
- keine gute Schueler-Option `jetzt mit aktuellem Kursfokus lernen`

Deshalb sollte das neue Feature **nicht** nur eine kleine Erweiterung von `filter_chapter_key` sein, sondern eine **zweite, kursweite Scope-Ebene**.

---

## Empfohlene Implementierung V1

### Kernidee

Wir fuehren einen **kursweiten Curriculum-Scope** ein:

- Er lebt auf **Kursebene**, nicht auf Pool-Ebene.
- Er besteht aus einer **Menge aktiver Kapitel**.
- Er wird auf die bereits vorhandenen Pool-Regeln **zusaetzlich** angewendet.
- Er ist standardmaessig **inaktiv**, damit bestehende Kurse unveraendert bleiben.

Logik:

```text
finale Fragebasis fuer Kursmodus
= Pool-Regeln des CoursePool
  INTERSECT
  optionaler Curriculum-Scope des Kurses
```

Damit bleiben beide Ebenen sauber getrennt:

- **CoursePool-Regel**
  dauerhafte, technische Begrenzung eines Pools
- **Curriculum-Scope**
  aktueller Unterrichts-/Kapitelstand des Kurses

---

## Produktverhalten V1

### Dozenten

Im Kursdetail bekommt der Dozent zusaetzlich einen neuen Bereich, z. B.:

- `Themensteuerung`
- oder `Aktuelle Kapitel`

Dort:

- Kapitel aller zugewiesenen Kurs-Pools werden aggregiert angezeigt
- gruppiert nach `handbook_title` / optional `exam_key`
- als Checkbox-Liste mit Titel und Reihenfolge
- Dozent kann Kapitel an-/abwaehlen
- optional ein schneller Schalter:
  - `Curriculum-Filter aktiv`
  - `Curriculum-Filter inaktiv`

### Schueler

#### Duel

Im Kurs-Duell soll die Auswahl **automatisch** gelten, wenn aktiv:

- Duel im Kurskontext nutzt standardmaessig nur Fragen aus der aktuellen Kapitelwahl
- wenn kein Curriculum-Filter aktiv ist, bleibt alles wie heute

Optional spaeter:
- kleiner Hinweis im Duel-Startscreen:
  - `Aktiver Kursfokus: Kapitel 3, 4, 5`

#### Andere Modi

In `Training`, `Leitner`, `Wahr/Falsch`, `Exam` soll der Schueler die Themenauswahl **bewusst zuschalten** koennen.

Empfohlene UX:

- Nach Wahl des Modus und vor Pool-Start:
  - kleiner Scope-Schalter
  - `Alle Fragen im Pool`
  - `Aktuelle Kursthemen`

Wenn kein Curriculum-Scope aktiv ist:

- Schalter ausgeblendet oder disabled mit Hinweis

Wenn aktiv:

- `Aktuelle Kursthemen` zeigt optional an, wie viele Fragen uebrig bleiben

---

## Datenmodell: empfohlene Variante

### Empfehlung

Neue Tabelle statt weiterer JSON-Spalten in `learning_courses`.

Warum:

- sauberer
- leichter erweiterbar
- mehrere Kapitel und spaeter evtl. Presets moeglich
- weniger unklare JSON-Semantik im Course-Entity

### V1-Tabellen

#### 1. `learning_course_curriculum_scopes`

Eine Scope-Konfiguration pro Kurs:

- `id`
- `course_id`
- `enabled` (bool)
- `label` nullable, z. B. `Aktuelle Kapitel`
- `handbook_key` nullable
- `handbook_title` nullable
- `created_at`
- `updated_at`

#### 2. `learning_course_curriculum_scope_chapters`

Aktive Kapitel in dieser Scope:

- `id`
- `scope_id`
- `chapter_key`
- `chapter_title`
- `chapter_order`
- optional `exam_key`

### Warum nicht einfach JSON in `learning_courses`?

Das waere fuer einen schnellen Hack moeglich:

- `curriculum_enabled`
- `curriculum_chapter_keys_json`
- `curriculum_handbook_key`

Aber ich wuerde es nur nehmen, wenn maximale Geschwindigkeit ueber Wartbarkeit geht. Da die App bereits stark kurszentriert ausgebaut wird, ist die separate Tabelle die bessere Basis.

---

## API-Design

### Neue Endpunkte

Empfehlung in [app/lib/Controller/CourseController.php](app/lib/Controller/CourseController.php) oder eigenem kleinen Controller:

- `GET /api/courses/{courseId}/curriculum-scope`
  - liefert:
    - `enabled`
    - `handbook_key`
    - `handbook_title`
    - `selected_chapter_keys`
    - `available_chapters`

- `PUT /api/courses/{courseId}/curriculum-scope`
  - nur Dozent
  - speichert:
    - `enabled`
    - `selected_chapter_keys`
    - optional `handbook_key`

### Erweiterung bestehender Responses

`CourseService::findById(...)` sollte fuer Schueler und Dozenten zusaetzlich liefern:

- `curriculum_scope`
- `curriculum_scope_available`

Dann kann [app/src/components/CourseDetail.vue](app/src/components/CourseDetail.vue) die UI direkt daraus rendern.

---

## Backend-Logik

### Neuer zentraler Resolver

Heute existiert:

```php
resolveCoursePoolContext(int $courseId, int $poolId, string $userId): array
```

Empfohlene Erweiterung:

```php
resolveCoursePoolContext(
    int $courseId,
    int $poolId,
    string $userId,
    ?string $scopeMode = null
): array
```

`$scopeMode` in V1:

- `null` oder `all`
- `curriculum`

Dann:

1. normale CoursePool-Regeln anwenden
2. wenn `scopeMode === 'curriculum'` und Kurs-Scope aktiv:
   - nur Fragen behalten, deren `chapter_key` in der aktiven Auswahl liegt

### Neuer Helfer in `CourseService`

Beispiel:

- `getCourseCurriculumScope(int $courseId, string $userId): array`
- `saveCourseCurriculumScope(...)`
- `applyCurriculumScopeToQuestionIds(int $courseId, array $questionIds): array`
- `getAvailableCurriculumChapters(int $courseId): array`

### Aggregation der verfuegbaren Kapitel

Die Kapitel sollten **ueber die aktuell zugewiesenen Kurs-Pools** aggregiert werden, nicht ueber alle Pools im System.

Das passt fachlich:

- Der Dozent steuert nur Material, das im Kurs auch wirklich zugewiesen ist.

---

## Frontend-Plan

### 1. Dozenten-UI in `CourseDetail.vue`

Neue Section oder Modal:

- `Aktuelle Kapitel / Themen`
- Checkbox-Liste
- Gruppierung nach Handbuch
- `Alle / Keine` Aktionen
- Speichern

Sinnvoller Ort:

- im bisherigen `Pools`-Tab fuer Dozenten
- direkt unter dem bestehenden Pool-Hinweis

### 2. Schueler-UI in Kursmodi

Im Kursmodus bei noch nicht gewaehltem Pool:

- kleine Bereichsauswahl:
  - `Alle Fragen`
  - `Aktuelle Kursthemen`

Diese Auswahl gehoert logisch in [app/src/components/CourseDetail.vue](app/src/components/CourseDetail.vue), nicht in jede einzelne Mode-Komponente als eigene, voneinander abweichende UX.

Dann wird der Scope mitgegeben an:

- [app/src/components/TrainingMode.vue](app/src/components/TrainingMode.vue)
- [app/src/components/LeitnerMode.vue](app/src/components/LeitnerMode.vue)
- [app/src/components/SwipeMode.vue](app/src/components/SwipeMode.vue)
- [app/src/components/ExamMode.vue](app/src/components/ExamMode.vue)
- [app/src/components/DuelMode.vue](app/src/components/DuelMode.vue)

z. B. als neuer Prop:

- `courseScopeMode`

### 3. Duel-UI

In [app/src/components/DuelMode.vue](app/src/components/DuelMode.vue):

- wenn `courseId` gesetzt und Curriculum-Scope aktiv:
  - kleinen Hinweis anzeigen:
    - `Kursthemen aktiv`
  - fuer V1 kein eigener Umschalter noetig, wenn Duel immer den Kursfokus anwenden soll

Alternativ, falls mehr Kontrolle gewuenscht:

- Umschalter `Alle Fragen` vs `Kursthemen`

Aber fuer V1 ist automatische Anwendung in Duel die klarere UX.

---

## Services / Controller, die angepasst werden muessen

### Sicher betroffen

- [app/lib/Service/CourseService.php](app/lib/Service/CourseService.php)
- [app/lib/Controller/CourseController.php](app/lib/Controller/CourseController.php)
- [app/src/components/CourseDetail.vue](app/src/components/CourseDetail.vue)
- [app/src/components/TrainingMode.vue](app/src/components/TrainingMode.vue)
- [app/src/components/LeitnerMode.vue](app/src/components/LeitnerMode.vue)
- [app/src/components/SwipeMode.vue](app/src/components/SwipeMode.vue)
- [app/src/components/ExamMode.vue](app/src/components/ExamMode.vue)
- [app/src/components/DuelMode.vue](app/src/components/DuelMode.vue)
- [app/lib/Service/TrainingService.php](app/lib/Service/TrainingService.php)
- [app/lib/Service/LeitnerService.php](app/lib/Service/LeitnerService.php)
- [app/lib/Service/DuelService.php](app/lib/Service/DuelService.php)

### Wahrscheinlich auch

- [app/appinfo/routes.php](app/appinfo/routes.php)
- neue DB-Entity/Mapper fuer Curriculum-Scope
- neue Migration

---

## Empfohlene Implementierungsreihenfolge

1. **DB-Schicht**
   - neue Tabellen/Migration fuer Curriculum-Scope
   - Entity + Mapper

2. **CourseService**
   - Laden/Speichern des Kurs-Scopes
   - Aggregation verfuegbarer Kapitel aus Kurs-Pools
   - Erweiterung von `resolveCoursePoolContext(...)`

3. **API**
   - `GET/PUT curriculum-scope`
   - `findById(...)` Payload erweitern

4. **Dozenten-UI**
   - Themen/Kapitel-Dialog in `CourseDetail.vue`

5. **Schueler-UI**
   - Scope-Auswahl in Kurs-Lernmodi
   - Duel standardmaessig ueber Kursfokus

6. **Services**
   - `TrainingService`, `LeitnerService`, `DuelService`
   - ggf. `Swipe/Exam` indirekt ueber Training-Pfade

7. **Smokes**
   - Lehrer setzt Kapitel 3+4 aktiv
   - Training mit `Kursthemen`
   - Duel im Kurs nutzt nur diese Kapitel
   - Umschalten auf `Alle Fragen` in Nicht-Duel-Modi

---

## Risiken / Knackpunkte

### 1. Kapitelmetadaten sind nicht ueberall gleich sauber

Die Logik steht und faellt mit der Qualitaet von `chapter_key`.
Bei Fragen ohne Kapitelmetadaten muss entschieden werden:

- immer ausschliessen, wenn Curriculum aktiv
- oder als `kapiteluebergreifend` optional mit eigener Checkbox behandeln

Empfehlung V1:

- Fragen ohne `chapter_key` werden bei aktivem Curriculum-Scope **nicht** gezogen.

### 2. Mehrere Handbuecher / gemischte Pools

Falls ein Kurs Pools aus unterschiedlichen Handbuechern enthaelt, wird die UI komplexer.

Empfehlung V1:

- Gruppierung nach `handbook_title`
- aber nur ein gemeinsamer Scope-Speicher
- spaeter koennte man pro Handbuch einen separaten Scope einfuehren

### 3. Leitner-Fairness

Wenn ein Nutzer im Leitner-Modus mal mit `Alle Fragen` und mal mit `Kursthemen` arbeitet, muss klar bleiben:

- Die Leitner-Items selbst sind weiter benutzer-/fragenbezogen
- Der Scope begrenzt nur, **welche Items aus der Queue gezeigt werden**

Das ist okay, muss aber im Code sauber als **Anzeige-/Startfilter**, nicht als neue Leitner-Datenbasis verstanden werden.

---

## Klare Empfehlung

Nicht an `filter_chapter_key` weiter herumbauen, sondern **kursweiten Curriculum-Scope als zweite Ebene** einfuehren.

Warum das die beste Variante ist:

- sauber getrennt von bestehenden Pool-Regeln
- keine stillen Verhaltensaenderungen fuer alte Kurse
- Mehrfachauswahl von Kapiteln moeglich
- exakt passend zu `Dozent steuert aktuelle Themen`
- einfach mit bestehendem `courseId`-Pfad in Training/Leitner/Duel zu verheiraten

---

## Konkreter V1-Satz

Wenn ich es morgen direkt bauen wuerde, dann so:

- neue Tabellen `learning_course_curriculum_scopes` + `learning_course_curriculum_scope_chapters`
- neue Kurs-API `GET/PUT curriculum-scope`
- Dozenten-Checkbox-Liste nach Kapiteln in `CourseDetail.vue`
- neuer `courseScopeMode` fuer Schueler:
  - `all`
  - `curriculum`
- Duel im Kurs nutzt standardmaessig `curriculum`, wenn aktiv
- Training/Leitner/Wahr-Falsch/Exam bekommen einen bewussten Scope-Schalter vor dem Pool-Start
- zentrale Schnittstelle bleibt `CourseService::resolveCoursePoolContext(...)`

Das ist klein genug fuer V1, aber sauber genug, dass man spaeter Presets, Wochenplaene oder mehrere Themen-Sets darauf aufbauen kann.

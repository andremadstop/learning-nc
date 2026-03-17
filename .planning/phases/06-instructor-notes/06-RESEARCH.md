# Phase 6: Instructor Notes - Research

**Researched:** 2026-03-17
**Domain:** PHP 8.1 / Vue 2.7 / PostgreSQL 16 — NC QBMapper pattern, DB migration, frontend field extension
**Confidence:** HIGH

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-----------------|
| NOTE-01 | DB-Migration adds `instructor_note` (TEXT) and `note_visible` (BOOLEAN) to `oc_learning_questions` | Migration pattern from Version002200 applies directly; idempotency guard with `hasColumn()` |
| NOTE-02 | QuestionForm provides textarea for `instructor_note` + visibility toggle | QuestionForm pattern (form.{field} + mounted() init + save() emit) is fully established |
| NOTE-03 | TrainingMode, ExamMode, LeitnerMode, SmartQueue show note when `note_visible=true` | All 4 modes already render `currentQuestion/currentItem.explanation` via NcNoteCard — identical pattern |
| NOTE-04 | Instructor sees own note regardless of `note_visible` (in edit mode) | Access gate via `canEditPool` already exists; QuestionForm always shows note field as the edit interface |
</phase_requirements>

---

## Summary

Phase 6 is a pure field-extension phase. The codebase already has a complete, proven pattern for adding optional fields to questions: the `pbq_subtype`/`pbq_config` pair added in Version002200 (2026-03-17) is the exact same shape — nullable column, Entity getter/setter, jsonSerialize inclusion, Service create/update threading, Controller param, and QuestionForm integration. Phase 6 follows this pattern verbatim for `instructor_note` (TEXT, nullable) and `note_visible` (BOOLEAN, nullable/default false).

The four learning mode components (TrainingMode, LeitnerMode, SmartQueue, ExamMode) each already display `currentQuestion.explanation` / `currentItem.explanation` via `<NcNoteCard v-if="..." type="warning">` in their post-answer feedback blocks. The instructor note display is identical in structure — just a second NcNoteCard conditioned on `note_visible === true`. ExamMode is a special case: during the exam itself no explanation is shown (only in the post-exam review list); the note should appear in the same review list, gated on `note_visible`.

The security model is clear: `canEditPool()` in QuestionService already distinguishes pool owners and edit-permission share holders from read-only students. NOTE-04 (instructor always sees own note) is handled at the frontend level: QuestionForm always shows the `instructor_note` field (it is the edit interface), while the 4 learning mode components condition display on `note_visible`. No new API endpoint or role check is needed.

**Primary recommendation:** Copy the Version002200 migration pattern, extend Entity/Service/Controller the same way pbqSubtype/pbqConfig were added, and add a single note display block to each of the 4 learning mode components mirroring the existing explanation NcNoteCard.

---

## Standard Stack

### Core (already in place — no new dependencies)

| Component | Version | Purpose | Where used |
|-----------|---------|---------|------------|
| NC QBMapper / SimpleMigrationStep | NC 29-31 | DB migration | `lib/Migration/` |
| OCP\AppFramework\Db\Entity | NC 29-31 | ORM entity | `lib/Db/Question.php` |
| Vue 2.7 + `@nextcloud/vue` NcCheckboxRadioSwitch | bundled | Boolean toggle UI | QuestionForm.vue |
| NcNoteCard | bundled | Note display in learning modes | TrainingMode, LeitnerMode, SmartQueue |

### No new installations required

All needed libraries are already present. No `npm install` or Composer additions.

---

## Architecture Patterns

### Established Question Field Extension Pattern

This is the canonical pattern, demonstrated by `pbq_subtype`/`pbq_config` in the same codebase:

**Step 1 — DB Migration (`lib/Migration/`)**

New file: `Version002300Date20260317000000.php` (next version after 002200).

```php
// Source: Version002200Date20260317000000.php (exact model)
class Version002300Date20260317000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('learning_questions')) { return null; }
        $table = $schema->getTable('learning_questions');

        if (!$table->hasColumn('instructor_note')) {
            $table->addColumn('instructor_note', 'text', [
                'notnull' => false,
                'default' => null,
            ]);
        }
        if (!$table->hasColumn('note_visible')) {
            $table->addColumn('note_visible', 'boolean', [
                'notnull' => false,
                'default' => false,
            ]);
        }
        return $schema;
    }
}
```

**Idempotency:** The `hasColumn()` guard makes the migration safe to run on both fresh installs and existing DBs.

**Step 2 — Entity (`lib/Db/Question.php`)**

```php
// In __construct(): addType declarations
$this->addType('instructorNote', 'string');   // TEXT maps to string
$this->addType('noteVisible', 'boolean');

// In jsonSerialize():
'instructor_note' => $this->instructorNote,
'note_visible' => $this->noteVisible ?? false,

// Getters/setters following existing pbqSubtype pattern:
public function getInstructorNote(): ?string { return $this->instructorNote; }
public function setInstructorNote(?string $v): void {
    $this->instructorNote = $v;
    $this->markFieldUpdated('instructorNote');
}
public function getNoteVisible(): bool { return (bool)($this->noteVisible ?? false); }
public function setNoteVisible(bool $v): void {
    $this->noteVisible = $v;
    $this->markFieldUpdated('noteVisible');
}
```

**Step 3 — Service (`lib/Service/QuestionService.php`)**

Add `?string $instructorNote = null, bool $noteVisible = false` to `create()` and `update()` signatures (after existing params). Thread through to entity setters. Follow the existing `if ($pbqSubtype !== null)` guard pattern.

**Step 4 — Controller (`lib/Controller/QuestionController.php`)**

Add `?string $instructorNote = null, bool $noteVisible = false` to `create()` and `update()` method signatures. Pass through to service calls.

**Step 5 — QuestionForm.vue**

Add to `form` data: `instructorNote: '', noteVisible: false`.

In `mounted()` when editing:
```js
this.form.instructorNote = this.question.instructor_note || ''
this.form.noteVisible = this.question.note_visible || false
```

In `save()` emit, add:
```js
instructorNote: this.form.instructorNote || null,
noteVisible: this.form.noteVisible,
```

UI — add after existing explanation textarea:
```html
<div class="form-group">
  <label for="instructor-note">{{ t('learning', 'Instructor Note (optional)') }}</label>
  <textarea id="instructor-note" v-model="form.instructorNote" rows="3" class="nc-input"></textarea>
  <NcCheckboxRadioSwitch :checked="form.noteVisible" @update:checked="form.noteVisible = $event" type="checkbox">
    {{ t('learning', 'Visible to students') }}
  </NcCheckboxRadioSwitch>
</div>
```

**Step 6 — Learning mode components (NOTE-03)**

Each of the 4 components has an `answered` feedback block that already shows `currentQuestion.explanation` or `currentItem.explanation`. Add the instructor note NcNoteCard immediately after the explanation card:

```html
<!-- TrainingMode / LeitnerMode / SmartQueue — after explanation NcNoteCard -->
<NcNoteCard v-if="currentItem.note_visible && currentItem.instructor_note" type="info">
  <strong>{{ t('learning', 'Note:') }}</strong> {{ currentItem.instructor_note }}
</NcNoteCard>
```

ExamMode shows no per-question feedback during the exam. In the post-exam review list (lines 196-235 of ExamMode.vue), add the note inside each `review-item` div after the existing answer detail blocks.

### QuestionList save handler — no update needed

`QuestionList.saveQuestion()` (line 148) uses a spread pattern:
```js
const { imageFile, removeImage, questionType, ...data } = questionData;
```
All remaining keys from the emitted event are forwarded to the axios PUT/POST call via `...data`. New fields `instructorNote` and `noteVisible` will automatically be included. No manual update of `saveQuestion` in QuestionList.vue is needed. CourseDetail.vue does not use QuestionForm directly.

### Recommended Project Structure (no change)

No new files except the migration. All changes are within existing files.

### Anti-Patterns to Avoid

- **Do not add a new API endpoint** for notes. The existing `PUT /api/questions/{id}` update endpoint handles this via the extended params — same as pbqConfig was added.
- **Do not add a new Controller** or Service method. Thread through existing `create()` and `update()`.
- **Do not use `v-html`** for note display — render as plain text via `{{ }}`. Notes are user-authored plain text, not HTML.
- **Do not strip `instructor_note` during active exam** (unlike explanation which is stripped). The note is shown in post-exam review only, and the frontend gates on `note_visible`. No server-side stripping needed.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Boolean toggle UI | Custom checkbox | `NcCheckboxRadioSwitch type="checkbox"` | Already used in QuestionForm for answer is_correct |
| Nullable boolean in PostgreSQL | Custom migration logic | `'notnull' => false, 'default' => false` in NC schema API | NC handles PostgreSQL dialect differences |
| Conditional field display | Custom visibility component | `v-if="item.note_visible && item.instructor_note"` | Single template expression is sufficient |

---

## Common Pitfalls

### Pitfall 1: Missing hasColumn() idempotency guard
**What goes wrong:** Migration fails on fresh install (CI) because column already exists from a later version, or breaks on re-run.
**Why it happens:** NC migration runner can re-execute migrations in edge cases.
**How to avoid:** Always wrap `addColumn()` in `if (!$table->hasColumn('...'))` — see Version002200 as the model.
**Warning signs:** CI migration test fails with "column already exists".

### Pitfall 2: BOOLEAN PostgreSQL nullable default
**What goes wrong:** `note_visible` comes back as NULL instead of false for existing rows.
**Why it happens:** PostgreSQL stores NULL for rows that existed before migration, even with `'default' => false`.
**How to avoid:** In `jsonSerialize()` use `$this->noteVisible ?? false` (already done for `lang`, `question_type`, etc. in the existing entity). In frontend, use `|| false` when reading from API response.
**Warning signs:** Toggle shows incorrect initial state when editing an existing question.

### Pitfall 3: Controller boolean param from JSON
**What goes wrong:** NC framework receives `note_visible: false` from JSON body but PHP gets it as empty string or null.
**Why it happens:** NC's request parsing may coerce JSON booleans.
**How to avoid:** Type-hint as `bool $noteVisible = false` in the controller method. NC parses JSON body booleans correctly into PHP bool when type-hinted.
**Warning signs:** Toggle saves as false even when set to true.

### Pitfall 4: ExamMode explanation-strip vs note
**What goes wrong:** Developer adds `instructor_note` to the `$stripCorrect` block in QuestionService, hiding notes from the API even after exam.
**Why it happens:** Confusion with the existing explanation stripping logic (QuestionService lines 200-216).
**How to avoid:** Do NOT strip `instructor_note` in the `$stripCorrect` block. The exam oracle concern is about `is_correct` and `explanation` — not instructor notes. Notes are controlled solely by `note_visible` flag, frontend-gated.

### Pitfall 5: ExamMode post-exam sortedDetailedResults sourcing
**What goes wrong:** Instructor note does not appear in the ExamMode post-exam review list even when `note_visible=true`.
**Why it happens:** `sortedDetailedResults` is built from `resultsData` (the exam completion API response), not directly from the `questions` array loaded at exam start. Whether the completion response includes full question data must be verified before adding the note NcNoteCard to the results screen.
**How to avoid:** Before adding note display to ExamMode results, check what data `res` (each item in `sortedDetailedResults`) contains. If it only has `questionText` and answer fields (no `instructor_note`), the `questions` array (loaded via `/api/pools/{id}/questions`) must be used to look up the note by question ID.
**Warning signs:** Note renders in TrainingMode/LeitnerMode/SmartQueue but not in ExamMode post-exam review.

---

## Code Examples

### Migration column addition (exact pattern)
```php
// Source: app/lib/Migration/Version002200Date20260317000000.php
if (!$table->hasColumn('pbq_subtype')) {
    $table->addColumn('pbq_subtype', 'string', [
        'notnull' => false,
        'length' => 50,
        'default' => null,
    ]);
}
if (!$table->hasColumn('pbq_config')) {
    $table->addColumn('pbq_config', 'text', [
        'notnull' => false,
        'default' => null,
    ]);
}
// instructor_note uses same 'text' type; note_visible uses 'boolean'
```

### Entity nullable default pattern
```php
// Source: app/lib/Db/Question.php jsonSerialize()
'lang' => $this->lang ?? 'de',
'question_type' => $this->questionType ?? 'single',
'review_status' => $this->reviewStatus ?? 'published',
// note_visible follows same pattern:
'note_visible' => $this->noteVisible ?? false,
```

### NcCheckboxRadioSwitch usage (existing in QuestionForm)
```html
<!-- Source: app/src/components/QuestionForm.vue line 54-60 -->
<NcCheckboxRadioSwitch
  v-if="form.questionType === 'single'"
  :checked="correctAnswerIndex === index"
  @update:checked="correctAnswerIndex = index"
  type="radio"
  name="correct-answer"
/>
<!-- For note_visible toggle, use type="checkbox" instead -->
```

### Explanation NcNoteCard (model for note display)
```html
<!-- Source: app/src/components/TrainingMode.vue line 69, 95 -->
<NcNoteCard v-if="currentQuestion.explanation" type="warning">
  <strong>{{ t('learning', 'Explanation:') }}</strong> {{ currentQuestion.explanation }}
</NcNoteCard>
<!-- Instructor note follows same pattern with type="info" and note_visible gate -->
```

### QuestionList spread — why no update needed
```js
// Source: app/src/components/QuestionList.vue line 148
async saveQuestion(questionData) {
  const { imageFile, removeImage, questionType, ...data } = questionData;
  // ...data includes ALL other emitted fields, including instructorNote + noteVisible
  await axios.put(url, data);
}
```

---

## Exam Mode Special Case

ExamMode does NOT show per-question feedback during the exam. The post-exam review screen (ExamMode.vue lines 196-235) uses `sortedDetailedResults` — a computed array built from `resultsData`. Each `res` item contains `questionText`, `isCorrect`, `isOpen`, `isMulti`, `answerDetails`, `userAnswerText`, `correctAnswerText`. It does NOT contain `instructor_note` or `note_visible` directly. The `questions` array (loaded at exam start from `/api/pools/{id}/questions`) DOES contain these fields from jsonSerialize(). To display the note in ExamMode review, build a lookup map `{ [questionId]: question }` from `this.questions` and reference it in the results template. This is the only ExamMode-specific implementation detail.

---

## Validation Architecture

No test framework detected (no pytest.ini, jest.config, vitest.config, or `__tests__` directory found). CLAUDE.md specifies manual testing via curl against `http://learning-dev:8080`.

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Command / Method |
|--------|----------|-----------|-----------------|
| NOTE-01 | Migration adds columns without data loss | manual-only | `ssh learning-dev 'docker exec learning-app php occ upgrade'` then `docker exec learning-db psql -U oc_admin oc_nextcloud -c "\d oc_learning_questions"` — confirm `instructor_note` + `note_visible` columns present |
| NOTE-02 | QuestionForm saves note + toggle | manual-only | Browser: create question with note text + toggle ON, reload page, edit same question — verify fields persisted |
| NOTE-03 | Note visible in all 4 learning modes when note_visible=true | manual-only | Browser: set note_visible=true, answer a question in each of TrainingMode/LeitnerMode/SmartQueue/ExamMode review — verify note appears in feedback |
| NOTE-04 | Instructor sees note in QuestionForm regardless of toggle | manual-only | Browser: set note_visible=false, open QuestionForm edit — note text and toggle (off state) must both appear |

### Wave 0 Gaps

None — no automated test infrastructure; all verification is manual per project convention.

---

## Open Questions

1. **ExamMode sortedDetailedResults structure at runtime**
   - What we know: template shows `res.questionText`, `res.answerDetails`, etc. — no `instructor_note`
   - What's unclear: whether `resultsData` from the `/api/training/complete` response embeds full question objects or only answer data
   - Recommendation: planner should add a task step to inspect `resultsData` structure and implement the `questions` lookup map approach described in the Exam Mode Special Case section

---

## Sources

### Primary (HIGH confidence)
- `app/lib/Migration/Version002200Date20260317000000.php` — canonical migration pattern for this codebase
- `app/lib/Db/Question.php` — Entity field/type/jsonSerialize patterns
- `app/lib/Service/QuestionService.php` — create/update signatures, canEditPool, stripCorrect logic
- `app/lib/Controller/QuestionController.php` — Controller param conventions
- `app/src/components/QuestionForm.vue` — form.data, mounted init, save emit, NcCheckboxRadioSwitch usage
- `app/src/components/QuestionList.vue` line 148 — spread operator confirms auto-forwarding of new fields
- `app/src/components/TrainingMode.vue`, `LeitnerMode.vue`, `SmartQueue.vue` — explanation NcNoteCard pattern
- `app/src/components/ExamMode.vue` — post-exam review list structure, sortedDetailedResults shape

### Secondary (MEDIUM confidence)
- NC Developer Manual (referenced in CLAUDE.md NotebookLM) — QBMapper, SimpleMigrationStep conventions

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — codebase is authoritative source, no external dependencies needed
- Architecture: HIGH — full precedent from pbq_subtype/pbq_config pattern in same codebase
- Pitfalls: HIGH — drawn from actual code analysis (nullable default pattern, stripCorrect block, QuestionList spread)
- ExamMode special case: MEDIUM — sortedDetailedResults runtime structure inferred from template, not verified against API response payload

**Research date:** 2026-03-17
**Valid until:** 2026-04-17 (stable codebase, no external dependencies)

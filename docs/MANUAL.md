# Learning — User & Administrator Manual

For **Learning 5.9.0** on Nextcloud 33–35.

This manual walks through the complete workflow, in the order you will actually use it:

> **Course → Learning materials → Pool/Test → Questions → Student → Test attempt → Results → Completion**

If you are evaluating Learning for a company, read [1. Concepts](#1-concepts) and
[2. Administrator setup](#2-administrator-setup) first, then follow
[3. The core workflow](#3-the-core-workflow) end to end. It takes about 30 minutes to
build your first working course.

> **A note on interface language.** Learning's source language is German; the interface
> ships in English, French, Russian, Arabic and Ukrainian. This manual quotes the English
> labels. If you run the interface in another language, the position of each control is
> the same. See [7. Languages and translations](#7-languages-and-translations).

---

## Table of contents

1. [Concepts](#1-concepts)
2. [Administrator setup](#2-administrator-setup)
   - 2.1 [Installation](#21-installation)
   - 2.2 [Global settings](#22-global-settings)
   - 2.3 [Letting teachers create courses and pools](#23-letting-teachers-create-courses-and-pools)
   - 2.4 [Creating user accounts](#24-creating-user-accounts)
3. [The core workflow](#3-the-core-workflow)
   - 3.1 [Create a question pool](#31-create-a-question-pool)
   - 3.2 [Add questions](#32-add-questions)
   - 3.3 [Create a course](#33-create-a-course)
   - 3.4 [Attach pools to the course](#34-attach-pools-to-the-course)
   - 3.5 [Add learning materials](#35-add-learning-materials)
   - 3.6 [Enrol students](#36-enrol-students)
   - 3.7 [Configure what students may do](#37-configure-what-students-may-do)
   - 3.8 [The student's test attempt](#38-the-students-test-attempt)
   - 3.9 [Results and monitoring](#39-results-and-monitoring)
   - 3.10 [Completion and certificate](#310-completion-and-certificate)
4. [Mandatory training and compliance](#4-mandatory-training-and-compliance)
5. [Bulk operations with occ](#5-bulk-operations-with-occ)
6. [Data protection and the AI features](#6-data-protection-and-the-ai-features)
7. [Languages and translations](#7-languages-and-translations)
8. [Troubleshooting](#8-troubleshooting)

---

## 1. Concepts

Learning has four objects. Understanding how they relate saves the most time:

| Object | What it is | Who owns it |
|--------|-----------|-------------|
| **Pool** | A collection of questions. The unit of content. Exists on its own. | The user who created it |
| **Question** | One item inside a pool: single choice, multiple choice, free text, or an interactive PBQ | The pool |
| **Course** | A container that groups pools, learning materials and enrolled people | Its instructors |
| **Membership** | A person in a course, with the role *student* or *instructor* | The course |

The key relationship: **a pool is not inside a course.** You create pools independently,
then *attach* them to one or more courses. The same pool can serve several courses — this
is how you reuse a "Workplace safety basics" pool across departments without copying it.

### Roles

Learning distinguishes three roles:

- **Nextcloud administrator** — installs the app, configures global settings (AI, tools),
  runs `occ` commands. Always counts as an instructor as well, but does not automatically
  see course content.
- **Instructor** — creates courses and pools, enrols students, sees all progress data of
  *their own* courses.
- **Student** — learns, takes tests, sees only their own results.

The instructor role has **two halves**, and confusing them is the single most common
setup question:

| | Where it comes from | What it grants |
|---|---|---|
| **Global instructor right** | Membership in one Nextcloud group — `learning-instructors` by default | May *create* courses and question pools at all. Without it, the **+ Create Course**, **+ Create pool**, **Add starter pool** and **Generate questions** buttons are not shown. |
| **Instructor of a course** | Creating the course, or being promoted by another instructor of it | Manage that course: enrol people, attach pools, see progress. |

So a teacher who should set up their own courses needs the group membership first — see
[2.3](#23-letting-teachers-create-courses-and-pools). Nothing else grants it, and it does
**not** require Nextcloud administrator rights.

Editing the questions of an existing pool is a third, separate right that belongs to the
pool, not to the role: the pool's **owner** and everyone it was shared with as **Can edit**
get the pool's **Manage** tab (add, edit, import, export questions), with or without the
global instructor right. See [3.2](#32-add-questions).

Everything else in Learning is scoped to the course. An instructor of course A cannot see
course B. This matters for a works council discussion: instructor visibility is not global.

---

## 2. Administrator setup

### 2.1 Installation

Learning is a normal Nextcloud app. Install from the App Store, or drop the release
tarball into `custom_apps/` and enable it:

```bash
sudo -u www-data php occ app:enable learning
```

Requirements: PHP 8.1+, Nextcloud 33–35, PostgreSQL or MySQL/MariaDB. The database
schema is created automatically by the migration steps on enable.

### 2.2 Global settings

**Settings → Administration → Learning.**

These settings apply to all users of Learning:

- **VirtuProf AI Assistant** — the AI provider and API key. Off by default. Learning is
  fully functional without it; the AI features simply stay hidden. See
  [section 6](#6-data-protection-and-the-ai-features).
- **Enabled simulator tools** — the interactive IT simulators, individually switchable.
  Disabled tools disappear for all users and cannot be re-enabled by an instructor.
- **Default language** — German or English. This is the fallback for new users only; each
  user can still choose their own Nextcloud interface language.
- **Instructor group** — the Nextcloud group whose members may create courses and pools.
  Defaults to `learning-instructors`. See
  [2.3](#23-letting-teachers-create-courses-and-pools).
- **Allow course-based instructor fallback** — off by default and best left off. When on,
  anyone who is already an instructor *of some course* also gets the global right to
  create new ones, which quietly widens the group you curated.
- **Daily Challenge enabled** / **Gamification enabled** — switch off the game elements
  (XP, levels, badges, streaks) if they do not fit your company culture. Worth considering
  for mandatory compliance training.
- **Max import file size (MB)** — raise this before importing large question sets.
- **Audit-Trail — Liveness** and **Support tickets** — operational settings.
- **Operator details (imprint and privacy notice)** — name, postal address and contact
  email, optionally a register entry and the person responsible for content. Shown on
  Learning's public imprint and privacy pages. Until name, address and email are entered,
  both pages show a neutral note instead of an operator, and Learning does not set the
  Nextcloud imprint/privacy links (Theming); links already set there to another address are
  never replaced. Saving with all fields empty removes the details. **Since 5.9.0 this is
  required:** earlier versions showed a fixed operator; after updating, enter your own
  details here.

If certificates have not been set up yet, this page shows a warning banner. Click
**Generate signing key** in the banner once (Nextcloud administrators only), or run
`occ learning:cert:init-issuer` on the server instead. See
[3.10](#310-completion-and-certificate).

### 2.3 Letting teachers create courses and pools

A teacher who should build their own content needs the **global instructor right**. It
comes from exactly one place: membership in the group configured as **Instructor group**
in the admin settings, `learning-instructors` unless you changed it.

This deliberately does *not* require Nextcloud administrator rights. Giving a teacher
full admin rights just to let them create a course would hand them every other Nextcloud
setting as well.

The group is **not created automatically** — Learning never touches your user directory.
Create it once:

1. **Settings → Administration → Users**, then **+ Add group** in the left sidebar.
2. Name it `learning-instructors` (or any name you prefer, then enter that name in
   **Settings → Administration → Learning → Instructor group**).
3. Add your teachers to the group.

Or from the command line:

```bash
sudo -u www-data php occ group:add learning-instructors
sudo -u www-data php occ group:adduser learning-instructors maria
```

The change takes effect on the teacher's next page load. They will then see
**+ Create Course** in the **Courses** tab, **+ Create pool** in the **Pools** tab, and a
**Manage** tab inside every pool they may edit.

> Nextcloud administrators always count as instructors, whether or not they are in the
> group. That is why an admin account sees every creation button from the start — and why
> testing the setup with an admin account tells you nothing about what a teacher sees.

### 2.4 Creating user accounts

Learning uses Nextcloud's own users — there is no separate user database. Create your
20–30 employees the usual way, or bulk-import them from CSV:

```bash
sudo -u www-data php occ learning:import-users /path/to/users.csv --group=Training
```

The command creates Nextcloud users from the CSV and optionally adds them to a Nextcloud
group. Run it with `--help` to see the expected column layout before you use it on real
data.

> **Tip for a pilot:** put all pilot participants in one Nextcloud group. It makes
> enrolling them into courses and cleaning up afterwards much easier.

---

## 3. The core workflow

This is the chain you asked about, in the order it actually works. Note that content
(pools and questions) comes **before** the course in practice — a course without pools
has nothing to show.

### 3.1 Create a question pool

**Requires the global instructor right** — Nextcloud administrator, or member of the
instructor group (see [2.3](#23-letting-teachers-create-courses-and-pools)). Without it
the buttons below are not shown.

Open **Learning** in the Nextcloud app bar. Learning has no left sidebar; its views are a
row of tabs along the top of the page (**Courses**, **Pools**, **Settings**, plus
**Today** and **Skill-Map** in the learner view). Click **Pools**.

Click **+ Create pool**. Give it a name and an optional description.

That is all a pool needs. It now exists, empty, owned by you.

To create several pools with their questions at once from a JSON file, use **Import pools**
in the same tab ([Importing whole pools in the Pools tab](#importing-whole-pools-in-the-pools-tab)).

**Naming matters more than it looks.** Pool names show up in the course view, in exam
configuration and in compliance reports. Use names that will still make sense to a
colleague in a year: "Occupational safety 2026 — machine operation", not "Test 1".

#### The textbook fields — and when to ignore them

Below the description the dialog shows five more fields. **All five are optional and most
pools should leave them empty.** They exist for one specific case: splitting a book or a
curriculum into one pool per chapter, so the chapters can be grouped and ordered.

| Field | What goes in | Example |
|-------|--------------|---------|
| **Handbook key** | A short identifier *you invent* for the book. Use the **same string in every pool from that book** — that is what ties the chapters together. Lowercase, no spaces. Max 64 characters. | `kammermann-network-plus` |
| **Handbook title** | The book title as learners should read it, with edition and year. | `Kammermann CompTIA Network+ (9th ed., 2024)` |
| **Chapter key** | An identifier for this one chapter, unique within that book. Also used to match pools when a course carries progress over from an earlier course. Max 64 characters. | `chapter-03` |
| **Chapter number** | The chapter's position in the book, 1–9999. Controls the order chapters are listed in — nothing else. | `3` |
| **Chapter title** | The chapter heading shown next to the pool. | `Switching basics` |

A correctly filled example, for the third chapter of one book:

```
Pool name        Network+ — Ch. 3: Switching basics
Description      Questions on VLANs, STP and switch configuration
Handbook key     kammermann-network-plus
Handbook title   Kammermann CompTIA Network+ (9th ed., 2024)
Chapter key      chapter-03
Chapter number   3
Chapter title    Switching basics
```

The next pool from the same book repeats `kammermann-network-plus` and
`Kammermann CompTIA Network+ (9th ed., 2024)` unchanged, and only changes the three
chapter fields to `chapter-04` / `4` / the next heading.

> **Two things these fields do not do.** They do not restrict which questions learners
> see — that is the course curriculum filter, and it reads the chapter fields on the
> **questions** ([3.2](#32-add-questions)), not on the pool. And they are not a category
> system: a pool that is not part of a book gains nothing from them. If you are unsure,
> leave all five empty. You can fill them in later by editing the pool; nothing breaks.

### 3.2 Add questions

**Requires edit rights on the pool**: you own it, or it was shared with you as **Can edit**.
The global instructor right is not needed for this — it is only needed to *create* a pool
(3.1). A pool you own also comes from **Import pools** in the **Pools** tab
([Importing whole pools in the Pools tab](#importing-whole-pools-in-the-pools-tab)) or from
`occ learning:import-pool-json --user=<you>` ([Importing with occ](#importing-with-occ)).

1. In the **Pools** tab, click the pool's card (under **My Pools**; pools others shared
   with you are under **Shared with me**).
2. The pool opens with a row of mode tabs: **Training**, **Leitner**, **Exam**, **Arena**,
   and, depending on your rights, **Statistics** and — last — **Manage**. A pool that has
   no questions yet opens directly in **Manage** for everyone who may edit it; in
   **Training**, an empty pool shows **Add questions**, which leads to the same place.
3. In **Manage**, click **+ Add question** and fill in the form.

The **Manage** tab is shown to everyone who may edit the pool: its owner and users with a
**Can edit** share. If you expect it and do not see it:

- **Show student view** is switched on. It shows the app as a learner sees it, without
  **Manage**, pool creation and import, and a yellow banner on every page says
  *Student view is on*. Click **Leave student view** in the banner (or switch it off under
  **Settings**).
- A red banner says your role could not be loaded. The app then falls back to the learner
  view; click **Try again**.
- The pool was shared with you as **Can view**. Instructors then see the last tab as
  **View questions**, without editing; others see no such tab. Ask the owner for
  **Can edit**.

The form fields are:

| Field | Options | Notes |
|-------|---------|-------|
| Scenario / info | free text | optional; shown above the question in every mode — a case, a situation, background the learner reads first. Plain text, line breaks kept, up to 10 000 characters |
| Question text | free text | supports Markdown |
| Answer type | **Single choice**, **Multiple choice**, **Free text** | picks how the answer is evaluated |
| Answers | 2–8 options | mark the correct one(s) |
| Difficulty | none, **Easy**, **Medium**, **Hard** | optional; drives the adaptive scheduling |
| Explanation | free text | shown to the student after answering — this is where the learning happens |
| Chapter | free text | groups questions; drives the chapter heatmap in the instructor view |
| PBQ subtype | **None (standard question)**, or CLI Terminal, Device Placement, Inline Dropdown, Cable Mapping, Multi-Panel, Switch Config, Routing Config, Diagnostic Review | for interactive performance-based questions |

**Write the explanation field.** It is optional and it is the single highest-value field
in the app. Without it, a wrong answer teaches nothing; with it, every wrong answer is a
micro-lesson.

**Use the scenario field for case-based training.** Questions are shuffled in every mode,
so information that a question depends on belongs into that question's scenario, not into a
separate question before it. If several questions share one case, give each of them the same
scenario. The scenario is part of the question: it is shown in exams too, and it is
translated together with the question text.

**Use the chapter field consistently.** It is free text, so "Ch. 1", "Chapter 1" and
"1. Safety" become three different chapters. Decide on a scheme before you enter 200
questions. The chapter heatmap — your best tool for spotting where a cohort struggles —
is only as good as this field.

#### Getting questions in without a shell or an AI key

Typing 200 questions through the form is not realistic. Three routes need neither server
access nor an AI provider:

- **Starter pools** — **Pools** tab → **Add starter pool** (instructors). Imports one of the bundled
  English sample pools (CompTIA A+, Network+, Security+, Cisco CCNA, AWS Cloud
  Practitioner basics; 20–30 questions each) as a new pool you own. Useful for trying
  the app; not company content.
- **Import into a pool** — **Manage** → **Import**: questions from CSV or JSON into the pool
  you opened, see below.
- **Import pools** — **Pools** tab → **Import pools** (instructors): a JSON file with one or
  more pools creates all of them with their questions, see
  [Importing whole pools in the Pools tab](#importing-whole-pools-in-the-pools-tab).
- **The form** — **+ Add question**, as above.

The two imports side by side:

| | Questions into an open pool | Import pools (Pools tab) |
|---|---|---|
| Where | the pool → **Manage** → **Import** | **Pools** tab → **Import pools** |
| Who | anyone with edit rights on that pool (owner or **Can edit** share) | instructors only: Nextcloud administrators and members of the instructor group ([2.3](#23-letting-teachers-create-courses-and-pools)) |
| Result | questions added to the pool you opened; its name and description stay | **new** pools, owned by you, each with its own questions |
| Formats | CSV and JSON, read leniently (answers as plain strings with a separate marker, several field aliases) | JSON in the pool format of `occ learning:import-pool-json` only, with the same strict rules as occ |
| Several pools in one file | not split up — use **Import pools** | yes, up to 50 |
| Limits per import | 500 questions | 50 pools and 5,000 questions in total (counted before any check) |
| File size | **Max import file size (MB)**, 2 MB by default | the same setting |
| Rate | 5 imports per minute per user | 5 imports per minute per user |
| Preview | before anything is saved | before anything is saved — a server-side check run with the same rules as the import |
| Errors | per question | per question and per pool; one pool that fails does not stop the others |

#### Importing CSV or JSON in the interface

Open the pool → **Manage** → **Import** (on an empty pool also **Import questions**).
The dialog has a **CSV** and a **JSON** tab. Drop or choose a file, or paste the data into
the text field. Which tab is used:

- **A file** goes by its extension: `.json` opens the JSON tab, `.csv` and `.txt` the CSV
  tab. A `.csv` or `.txt` file whose content is valid JSON (a list or an object) switches to
  JSON.
- **Pasted text** switches from CSV to JSON by itself only when it is valid JSON — a list
  or an object. Text that merely starts with `[` or `{`, such as a CSV question
  `[Network] Which port does SSH use?,…`, stays CSV. Once you have clicked the other tab
  yourself, pasting no longer switches; removing a loaded file with **Clear** turns the
  automatic switch back on.
- **Broken JSON** (it starts with `[` or `{` but cannot be read) stays on the CSV tab. When
  nothing in it can be imported as CSV, a note says that it looks like JSON, quotes the
  error (for example a missing bracket or a stray comma) and points to the JSON tab.

For CSV and for JSON, a preview below the text field shows the first questions and how many
of them can be imported, with the reason for each one that cannot, before anything is saved.
The questions are added to the pool you opened.

**CSV** — one question per record:

```
question,answer1,answer2,...,answerN,correct_number,explanation
What is 2+2?,3,4,5,6,2,Because math
What is H2O?,Water,open
"Which command lists files?
(Linux shell)",ls,cd,pwd,1,"ls lists the directory.
It takes options such as -l."
```

`correct_number` is **1-based** (1 = first answer); the explanation is optional; a header
row is detected and skipped; comma or semicolon as separator (whichever splits the first
question into more fields). A field in double quotes may contain the separator and line
breaks, and `""` stands for one quote character — the format spreadsheet programs write.
A backslash is an ordinary character, also in front of a quote. "Line N" in the import
report and the 500-question limit count questions, not physical lines; a header row does
not count towards the limit. A CSV export (`occ learning:export-pool <id> --format=csv`)
uses the same quoting, so its texts come back as they were; it carries only text, answers,
one correct answer and the explanation — for a complete round trip use the JSON export. The three-field form `question,model_answer,open` creates a free-text question. A
CSV question always has exactly one correct answer.

**JSON** — the importer accepts:

| Shape | Example |
|-------|---------|
| A list of questions — also what **Export JSON** writes | `[{…}, {…}]` |
| An object with a question list (`questions`, also `fragen` or `items`) | `{"questions": [{…}, {…}]}` |
| The `occ import-pool-json` format with **one** pool | `{"_meta": {…}, "pools": [{"name": …, "questions": [{…}]}]}` |
| A single question | `{"text": …, "answers": […]}` |

A file with several pools is not split up here, because the questions all go into the pool
you opened. Import it with **Import pools** in the **Pools** tab, which creates one new pool
per entry ([Importing whole pools in the Pools tab](#importing-whole-pools-in-the-pools-tab)),
or with `occ learning:import-pool-json` ([Importing with occ](#importing-with-occ)).

When the file is in the pool format, its pool and `_meta` fill in what a question does not
carry itself — the same rule as the occ command, **question > pool > `_meta`**:

| Question field | Taken from, if the question has none |
|---|---|
| `chapter_key`, `chapter_title`, `chapter_order` | the pool |
| `handbook_key`, `handbook_title` | the pool, else `_meta` |
| `exam_key` | the pool's `exam_key_prefix` as `<prefix>-q<original_number>` (without `original_number`: the question's position, counted from 1) |
| `difficulty` | `_meta.default_difficulty` |
| type | `_meta.default_question_type`, unless several answers are marked correct — then the question is multiple choice, whichever type the default names (`pbq` included) |

A value the question has itself is never overwritten, and nothing is invented: a field that
is missing everywhere stays empty. The pool's `name` and `description` are **not** applied —
the questions go into the pool you opened, which keeps its own name and description.

Each question needs its text and answers. The text is read from `text`, `question`,
`question_text` or `frage` — for interactive questions as well. Answers can be written
in two ways:

- **Objects** with a correctness flag:
  `"answers": [{"text": "Paris", "is_correct": true}, {"text": "Rome", "is_correct": false}]`
  (`correct` is read as well).
- **Plain strings**, under `answers`, `options` or `choices`, plus a field on the question
  that names the right one:

  | Field | Meaning | Example |
  |-------|---------|---------|
  | `correct` or `answer` | a **0-based** index (`0` = first), a letter (`"B"`) or the answer text; a list such as `["B", 3]` marks several answers | `"options": ["Berlin", "Paris", "Rome"], "answer": 1` |
  | `correct_index` | 0-based index | `"correct_index": 0` |
  | `correct_number` | 1-based number, as in CSV | `"correct_number": 2` |
  | `correct_answer` | the answer text | `"correct_answer": "Paris"` |

  If an answer's text is itself `"A"` or `"2"`, a marker with that value means that
  answer, not a letter or an index. The question is skipped, with the reason in the import
  report, when a marker matches no answer (index out of range, unknown letter or text), when
  a list names the same answer twice (for instance `["B", 1]`), or when a list has more
  entries than the question has answers. A list of 0/1 values, one per answer (such as
  `[1, 0, 1, 0]`), is **not** supported: list the correct answers instead (`[0, 2]`).

Note the difference to CSV: the CSV `correct_number` counts from 1, a JSON number under
`correct`, `answer` or `correct_index` counts from 0. Only the field named `correct_number`
counts from 1 in JSON as well.

Optional fields: `explanation`, `difficulty` (`easy`, `medium`, `hard`; a value longer
than 20 characters is cut to 20), `scenario`, `chapter_key`, `chapter_title`,
`chapter_order` (a whole number from 1 to 9999; a value outside, such as 0, a negative
number or 10000, is dropped without a message, in the interface and in occ alike),
`handbook_key`, `handbook_title`,
`exam_key`. The type is read from `type` or its alias `question_type`. `open` makes a
free-text question (the first answer, or `answer`, is the model answer) and `pbq` an
interactive one. For a choice question, several answers marked correct always make it
multiple choice, even under `"type": "single"`; `"type": "multi"` stays multiple choice
with a single correct answer too (an export writes it that way). Without a type, one
correct answer makes it single choice. If the file names no correct answer at all, the
first answer is set as correct and the import report shows a warning — check those
questions.

Valid types are `single`, `multi`, `open` and `pbq`, in any letter case; the old names
`true_false` and `multiple` are read as `single` and `multi`. Any other type (also in `_meta.default_question_type`)
skips that question with the reason in the import report — the rest of the file is
imported. The same applies to `occ learning:import-pool-json` and the pool import in the
Pools tab.

An interactive question (`"type": "pbq"`) has no `answers`; it needs `subtype` (one of
`dropdown`, `placement`, `cli`, `cable`, `multi_panel`, `switch_config`, `routing_config`,
`diagnostic`, `ranking`) and a `pbq_config` object. Its difficulty defaults to `medium`.
The layout of `pbq_config` differs per subtype and is easiest to take from an export of a
question built in the form.

Limits per import:

- at most **500** questions; in CSV a header row does not count
- at most **2 MB** by default; an administrator can set 1–10 MB under
  **Max import file size (MB)** ([2.2](#22-global-settings))
- 2–8 answers per choice question. In JSON, answers beyond the eighth are dropped with a
  warning — unless one of them is marked correct, then the question is skipped; a question
  with more than 50 answers is skipped as well. In CSV the line is rejected
- question text up to 5,000 characters and answer text up to 2,000. Longer text is cut,
  with a warning in the import report for each question — except a CSV question text over
  5,000 characters, which rejects the line; scenario up to 10,000 characters, otherwise the
  question is skipped
- 5 imports per minute per user

After the import the dialog reports how many questions were imported and lists skipped
questions with the reason.

**Template:** create two or three questions by hand, then open the **⋯** menu next to
**Import** and choose **Export JSON** (or run
`occ learning:export-pool <pool-id> --format=json`). The exported file is a plain list of
questions — the best model for your own files. Per question it carries text, type,
scenario, explanation, difficulty, chapter (`chapter_key`, `chapter_title`,
`chapter_order`), `handbook_key`, `handbook_title`, `exam_key` and the answers with
`is_correct`; an interactive question carries its `subtype` and `pbq_config` instead of
answers. Empty fields are left out. The file goes back in unchanged, through
**Manage → Import** into any pool you can edit, or as a new pool with **Import pools** in the
**Pools** tab or the occ command below.
Not exported: translations of questions, instructor notes and attached images.

#### Importing whole pools in the Pools tab

**Requires the global instructor right** — Nextcloud administrator, or member of the
instructor group ([2.3](#23-letting-teachers-create-courses-and-pools)), the same right as
**+ Create pool**. Edit rights on existing pools are not enough, because this import creates
new pools. The server checks the right itself; without it the import is refused.

**Pools** tab → **Import pools**. Choose a JSON file or paste its content. The file is in the
format of `occ learning:import-pool-json`, described in
[`docs/import-pool-workflow.md`](import-pool-workflow.md):

- `{"_meta": {…}, "pools": [ {"name": …, "questions": [ … ]}, … ]}` — every entry under
  `pools` becomes a new pool, named by its own `name`, with its description, chapter fields
  and questions;
- a plain list of questions, such as an export, or `{"name": …, "questions": [ … ]}` — one
  new pool. It takes the file's `name` if it has one, else the name of the uploaded file
  without extension (an export `pool-12.json` becomes the pool `pool-12`; rename it
  afterwards), else *Imported pool*.

The import is one step for all pools in the file, and it reads the file by the same rules as
the occ command — the same code runs in both. That means answers only as objects with
`is_correct` (the plain-string forms of the question import are not read), `scenario` only
under that name, 2–8 answers per choice question, at least one answer marked correct
(exactly one when the question itself says `single`; there is no fallback to the first
answer), and `medium` as the difficulty of a question without one. CSV is not accepted here.

Before anything is saved, a preview lists every pool in the file with its name, how many of
its questions can be imported out of how many it holds, and the reasons for each pool or
question that cannot. The preview is not a guess made in the browser: the server runs the
whole import with every check and writes nothing. The real import then gives the same
result, unless the database itself fails.

After the import, the result lists every pool: created or not, how many questions were
imported, and what was skipped and why. Each new pool is owned by you, can be edited in its
**Manage** tab, attached to courses and shared like a pool you created by hand.

What happens on errors:

- **A question that is refused** (missing text or answers, too few or too many answers, text
  too long, unknown PBQ subtype, …) is skipped with the reason, shown as "Q*n*: …", where
  *n* is the question's `original_number` or else its position in the pool, counted from 1.
  The pool keeps its other questions.
- **A pool without a name, with a name over 200 characters or a description over 2,000** is
  skipped before anything is written. The same goes for a pool whose handbook or chapter key
  is longer than 64 characters, whose handbook or chapter title is longer than 255, or whose
  chapter number is outside 1–9999; the preview already says so. The other pools are imported.
- **A pool that cannot be saved** is rolled back completely — no half-imported pool stays
  behind — and listed with *create failed — skipped*. Each pool is saved on its own, so the
  pools before and after it stay. The technical reason is written to the Nextcloud log, not
  shown in the dialog.
- **A pool whose questions are all refused is still created**, empty, with the reasons
  listed. A pool with no questions at all is created as well, with the warning that it
  contains no questions.
- **The file as a whole is refused**, with nothing written, when it is empty, not valid
  JSON, in none of the accepted shapes, contains no pools, more than 50 pools or more than
  5,000 questions in total, or is larger than **Max import file size (MB)**
  ([2.2](#22-global-settings)). Split a larger catalogue into several files.

Importing the same file twice creates its pools twice; nothing is matched against pools that
already exist. The dialog cannot be closed while the import runs. If the import gets no
answer — the connection drops, or a proxy gives up on a large file — its result is unknown:
the dialog says so, reloads the pool list and does not offer the same import again. Check the
list before you import the file once more.

Limits: 50 pools and 5,000 questions per import (the questions are counted before any
check), the file size from **Max import file size (MB)** (2 MB by default, 1–10 MB), and
5 requests per minute per user — the preview is one of them, so a preview plus an import
count as two.

#### Importing with occ

For catalogues beyond the limits of the interface, or a scripted rollout, an administrator
can import on the server. Several pools at once also work in the interface, with
**Import pools** in the **Pools** tab
([Importing whole pools in the Pools tab](#importing-whole-pools-in-the-pools-tab)); both use
the same import code, so a file gives the same pools either way. The command:

```bash
sudo -u www-data php occ learning:import-pool-json /path/to/questions.json --user=<owner-uid> [--pool-name=<name>] [--dry-run]
```

The command **creates new pools**, owned by `--user`; it does not add to an existing one.
It reads:

- the pool format `{"_meta": {…}, "pools": [ … ]}` — any number of pools, each named by its
  own `name`;
- a plain list of questions, such as an export, or `{"name": …, "description": …,
  "_meta": {…}, "questions": [ … ]}` — one pool, named by `--pool-name`, else the file's
  `name`, else the file name without extension (an export is called `pool-<id>.json`, so
  pass `--pool-name` for a readable name). `--pool-name` together with a `pools` file is
  refused.

`--dry-run` runs every check of the real import — of the file, of each pool and of each
question — and lists the pools with their question counts and every pool or question that
would be skipped, without writing anything. The occ command, like **Import pools**, reads
answers only as objects with `is_correct`, not as plain strings with a separate marker. It
has no limit on the number of pools or questions, and its output is always in English. Each
pool is saved on its own: if one cannot be saved, it is rolled back completely, the reason
goes to the Nextcloud log, and the other pools stay. The schema, and a ready-made prompt for having an AI
convert an existing PDF question catalogue into it, are in
[`docs/import-pool-workflow.md`](import-pool-workflow.md).

#### Generating questions with AI

Only available when an administrator has connected an AI provider — Google Gemini with an
API key, or a local Ollama server ([2.2](#22-global-settings), [section 6](#6-data-protection-and-the-ai-features))
— and the user has given AI consent. Two entry points:

- **Manage** → **Generate with AI** (the button appears only when a provider is
  configured): paste source text (50–50,000 characters), choose 5–30 questions, review and
  edit the generated questions, then import the selected ones into this pool.
- **Pools** tab → **Generate questions**: creates a **new** pool from pasted text or from a
  file in your Nextcloud.

Without consent, the request is refused and the consent dialog is offered; nothing is sent.
Review every generated question before learners see it.

### 3.3 Create a course

Open the **Courses** tab and click **+ Create Course**. You become its instructor
automatically.

> No create button? You are not in the instructor group — see
> [2.3](#23-letting-teachers-create-courses-and-pools).

A course carries a title, a description, and optionally an exam date used for the
student countdown.

### 3.4 Attach pools to the course

In the course, open the **Administration** tab.

Attach the pools you created in 3.1. For each attached pool you decide whether it is
**required**. Required pools count towards course completion and towards certification;
optional pools are practice material.

This required/optional distinction is what turns a pile of questions into a defined
training programme. Set it deliberately.

### 3.5 Add learning materials

Open the course's **Learning space** tab → materials.

Learning does not store documents itself. You **link a Nextcloud folder**:

1. Enter the path in **Material folder**, e.g. `/CourseMaterial`
2. Click **"Link folder"**
3. Upload your PDFs and Markdown files into that folder in Files
4. Click **Scan folder**

The scan registers the documents so students see them in the course. The table columns
are **File name**, **Type**, **Status**, **Size**, **Uploaded** and **Action**.

Because it is a normal Nextcloud folder, all the usual mechanics apply: share it with a
group, keep versions, sync it with the desktop client. A group folder works well when
several instructors maintain the material together.

If AI is enabled, **"Extract all"** additionally indexes the documents so
the AI tutor can cite them. Without AI, the documents are simply available for download.

### 3.6 Enrol students

Course → **Participants** tab.

Type a Nextcloud username into **"Enter username to add..."** and click **Add**. The
person appears in the member list with the role *student*.

**Make Instructor** promotes a member to co-instructor; **Make Student** demotes them.
Co-instructors see the same progress data you do — worth a thought before promoting.

There is no self-enrolment: someone is in a course because an instructor put them there.
For a 20–30 person pilot, adding them one by one is a few minutes' work.

### 3.7 Configure what students may do

Course → **Administration** tab. This is where a course becomes a
*training programme* rather than a flashcard box.

**Course rules — learning modes** — switch individual modes on or off. Disabled modes are
not shown to students. The modes are:

| Mode | What it does | Use it for |
|------|--------------|-----------|
| **Training** | quick quiz, immediate feedback | everyday practice |
| **Leitner** | 5-box spaced repetition, due cards only | long-term retention |
| **Exam** | timed, no feedback until the end, scored | the actual assessment |
| **Arena** | duels and multiplayer game shows | engagement, optional |

For a corporate knowledge assessment, a common setup is: Training and Leitner on, Arena
off, Exam on.

**Course rules — tools** restricts the simulators per course.
Globally disabled tools stay locked.

**Exam date** sets the date and time used for the countdown on the
student dashboard and for the exam reminders. It does not open or close any exam.

**Course schedule** plans chapters with a **Start date** and a **Target date**.
Students see it as a timeline.

**Talk room** links a Nextcloud Talk room to the course — paste the token
from the Talk URL.

**Maintenance mode** keeps a small daily review portion running after the course ends,
scheduled by the FSRS algorithm. For compliance training this is how knowledge survives
past the test date.

### 3.8 The student's test attempt

A student opens **Learning**, sees their course, and picks a mode.

In **Exam mode** the student picks a pool and sees up to two cards:

- **Exam** — up to 90 questions from the pool (all of them if the pool is smaller),
  1.5 minutes per question, at most 120 minutes. In a course with certification enabled,
  it is judged by the course's **Minimum score (%)** and counts towards the certificate
  when the pool is one of the **Required pools** (or none are set); otherwise the result
  is shown on the 720/900 scale.
- **Practice run** — only when the course offers practice exams. It uses the course's
  practice settings (number of questions, time limit, pass mark), ends with a review with
  explanations, and never counts towards a certificate.

The exam itself is deliberately exam-like:

- a time limit, with automatic submission when it expires
- no feedback until the end
- questions can be skipped and revisited
- hotkeys 1–8 select an answer, Enter confirms
- the attempt is locked to one browser tab; a second tab is read-only
- an interrupted attempt resumes from server state — closing the laptop does not lose it

At the end the student sees **Passed** / **Not passed**, the score against the passing
score, the number of correct answers, and the time taken.

**Training mode** is the opposite: immediate feedback and the explanation text after every
question. **Leitner** shows only cards that are due, moving them between five boxes.

### 3.9 Results and monitoring

Course → **Participants** tab → student progress.

You get:

- **Student progress table** — level, XP, critical cards, overall mastery, last active,
  with **Export CSV**
- **At-risk students** — automatic High Risk / Medium Risk flags based on accuracy,
  critical card count and inactivity. This is the list to actually act on
- **Chapter heatmap** — where the whole cohort struggles, which usually means the
  *material* needs work, not the people

The instructor dashboard aggregates this across your courses.

> **Privacy note.** This is individual-level performance data about employees. In
> Germany and much of the EU that makes it co-determination relevant — involve your works
> council before a rollout, not after. The data never leaves your server, which helps the
> conversation, but does not replace it.

### 3.10 Completion and certificate

Course → **Administration** tab → **Certification**.

- **Enable certification**
- **Minimum score (%)** — the passing threshold
- **Required pools** — which pools must be completed
- **Validity duration (days, 0 = no expiry)** — for recurring training

Once enabled, a learner who meets the criteria is automatically issued a
cryptographically signed (Ed25519) certificate. It can be printed as A4 and carries a QR
code and a certificate ID.

**Before this works, generate the issuer key once.** A Nextcloud administrator clicks
**Generate signing key** in the warning banner under **Settings → Administration →
Learning**, or runs:

```bash
sudo -u www-data php occ learning:cert:init-issuer
```

The private key is encrypted at rest. Without this step, no certificate can be minted.

Anyone can then verify a certificate at its permanent URL without logging in. The
verification checks, in order: signature → issuer key status → claim binding → revocation
→ expiry. All of them must pass. No personal data beyond what is on the certificate is
exposed on the verification page.

Certificates can be revoked by the course owner; a revoked certificate verifies as
withdrawn rather than valid.

---

## 4. Mandatory training and compliance

For a manufacturing company this is likely the part you actually need.

Learning can mark a course as **mandatory** for a set of people, track certificate expiry,
and send recertification reminders automatically. Combined with **Validity duration** from
3.10, this gives you the annual-safety-briefing loop:

1. Employee completes the course, receives a certificate valid for e.g. 365 days
2. Before expiry, an automated reminder goes out
3. The employee retakes the course; a new certificate is issued
4. Every issuance, revocation and completion is written to a tamper-proof audit trail

**Compliance reports** give an organisation-wide completion overview for audits — who has
completed what, and what has expired.

You can verify the audit chain's integrity at any time:

```bash
sudo -u www-data php occ learning:audit:verify
```

---

## 5. Bulk operations with occ

All commands run as the web server user, from the Nextcloud root:

| Command | Purpose |
|---------|---------|
| `learning:import-users <csv>` | create Nextcloud users from CSV, optionally into a group |
| `learning:import-pool-json <file>` | bulk-import question pools as new pools — a `pools` file, or a plain question list such as an export (`--pool-name`); the same import as **Import pools** in the **Pools** tab; see [`import-pool-workflow.md`](import-pool-workflow.md) |
| `learning:export-pool <id>` | export a pool as JSON or CSV |
| `learning:export-course <id>` | export a course with pools, members and statistics |
| `learning:archive-course <id>` | snapshot a course and set it to archived |
| `learning:merge-course <src> <dst>` | merge one course into another |
| `learning:import-vault <path>` | import a Markdown document tree for the AI tutor |
| `learning:cert:init-issuer` | generate the certificate signing key (run once) |
| `learning:audit:verify` | verify audit chain integrity |
| `learning:uninstall` | remove all Learning data from the database |

`learning:uninstall` is a **dry run unless you pass `--execute`**. Run it without the flag
first and read what it reports.

Every command supports `--help`. Test destructive ones on a staging instance first.

---

## 6. Data protection and the AI features

Learning works completely without AI. The AI features need a connection that the
administrator sets up — Google Gemini (API key) or a local Ollama server — and are
**off by default**.

When AI is switched on, two gates still apply:

1. The administrator must enable it globally
2. **Each user must individually opt in.** Every path that would send data to the model
   checks that consent first, including the weekly background note. Without consent, the
   feature returns a "consent required" state and no data is transmitted. The consent
   belongs to one consent text version **and** one recipient: if the administrator
   switches to another recipient, or the text is revised, every user is asked again
   before anything is sent. A user can withdraw at any time under *Personal settings →
   AI consent*; the withdrawal applies to every request that starts afterwards.

What is sent when a user has consented: their messages, the current question with
answer options and explanation, excerpts from course material, information from their
learning profile (goals, motivation, strengths and weaknesses), their learning state, their
recent VirtuProf messages with the replies (stored on the server and sent again with later
requests; older chats as a summary) and — once a week, automatically —
their weakest topic with the questions they got wrong. Name, email address and account
data are not sent automatically. Contributions of other learners are not sent to an
external recipient. The text the learner sees is `app/data/ai-consent.json`; the public
privacy page (`/privacy`) shows the same list with the recipient in force. The audit log
records each AI request without its content; a personal data export contains the stored
consent and the stored chat messages and summaries, and deleting the account removes them.

This is a deliberate GDPR design: opting out leaves you with a reduced but fully working
app, never a broken one.

If your company policy forbids sending content to a third-party model — a normal position
for a manufacturing enterprise — simply leave AI disabled. You lose the AI tutor, question
generation and automatic notes. Pools, courses, exams, certificates, compliance reports
and all simulators are unaffected.

### Required videos from Vimeo or YouTube

A course's required content can include Vimeo or YouTube videos (source `vimeo` or
`youtube-nocookie`, with the provider's video ID such as `dQw4w9WgXcQ` — not the full URL).
Before the learner clicks **"Load and watch"**, nothing is loaded from the provider: no
frame, no script, no cookie. After that click the page loads the provider's player
(YouTube only from `youtube-nocookie.com`) **and the provider's player script**
(`player.vimeo.com/api/player.js` or `www.youtube.com/iframe_api`). The script tells
Learning which parts were watched; the server credits them under the same rules as a video
from Nextcloud Files (real watch time, 95 % coverage, skipped parts do not count) and
deletes the watched segments once the video is completed. The provider receives the
learner's IP address and may collect its own data; the privacy page lists this under
*Externe Videos (Vimeo / YouTube)*.

---

## 7. Languages and translations

### 7.1 Current state

Learning ships six interface languages: **German** (source), **English**, **French**,
**Russian**, **Arabic** and **Ukrainian**.

> **None of the translations have been reviewed by a native speaker** — English included.
> All five non-German catalogues were produced with machine assistance from the German
> source, and Ukrainian is the newest of them, added in response to a user request.
> Corrections are very welcome — see 7.3. If you spot a wrong or awkward string, an issue
> naming the English text and your correction is enough; you do not need to touch the code.

Two independent language layers exist — this distinction matters:

| Layer | What it covers | Where it lives |
|-------|----------------|----------------|
| **Interface language** | buttons, labels, menus | `app/l10n/<lang>.json` + `.js` |
| **Content language** | optional parallel translations *of your questions* | database, currently `de`, `en`, `ru`, `ar` |

**You can write questions in any language, including Ukrainian, right now.** The content
language layer is only for maintaining *parallel translations* of the same question. A
question you type in Ukrainian is stored and displayed as-is — no restriction applies.

### 7.2 A note on untranslated strings

Until recently a number of interface strings were missing from the translation catalogue
entirely. Those fell back to the German source text and appeared **in German in every
language**, including English — the course materials tab, parts of the course
administration tab and the answer-type selector in the question form were all affected.

Those strings have now been added to the catalogue and translated into all six languages.
If you still find German text in a non-German interface, that is a bug worth reporting:
name the screen and the text you see.

### 7.3 How to add or fix a translation

Translations live in `app/l10n/`. Each language has two files that must stay in sync:

- `<lang>.json` — the source of truth, read by the backend and the App Store
- `<lang>.js` — a generated file, read by the frontend

The VirtuProf assistant additionally keeps its own runtime catalogue in
`app/src/l10n/virtuprof-strings.js`, with one exported dictionary per language. A language
present only in `l10n/` leaves the assistant speaking its fallback language.

The keys are the **German source strings**, byte for byte — never edit a key.

**To correct an existing translation**, edit the *value* in `app/l10n/<lang>.json`,
regenerate the `.js` and run the checks:

```bash
python3 scripts/l10n_js_sync.py
./scripts/check-i18n-parity.sh
```

**To add a new language** (using `uk` as the example):

1. Copy `app/l10n/en.json` to `app/l10n/<lang>.json` and translate the *values*, leaving
   the keys untouched
2. Set the correct plural form in the file header. Ukrainian, for instance, needs three
   forms:
   ```
   nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);
   ```
3. Register the language code in five places — miss one and the translation silently
   never ships:
   - `scripts/l10n_js_sync.py` → `LANGS` (otherwise no `.js` is generated)
   - `scripts/check-i18n-parity.sh` → `langs` (otherwise it is not guarded)
   - `scripts/deploy-prod.sh` → the `for lang in …` list (otherwise it never reaches the
     server)
   - `app/src/utils/virtuprof-i18n.js` → the import, `ALLOWED` and `DICTS`
   - `app/lib/Controller/VirtuProfController.php` → `ALLOWED_INTERFACE_LANGUAGES`
     (an unlisted language is silently coerced to empty, with no error)
   - `scripts/gen-virtuprof-strings.mjs` → `LANGS`
   - `app/lib/Service/GeminiService.php` → `SUPPORTED_LANGUAGES` **and** the `$langMap` in
     `buildSystemPrompt()` (an unlisted language makes the AI prompt say "default to
     English", so the assistant answers in the wrong language)
4. Regenerate the VirtuProf catalogue — it is a **generated** file, never edited by hand:
   ```bash
   node scripts/gen-virtuprof-strings.mjs
   ```
5. Generate and verify as above.

The check runs five gates: every language has exactly the same key set as `de.json`; each
`.js` matches its `.json`; every value carries the same placeholders as its German source;
every translatable literal in the source code actually exists in the catalogue; and the
VirtuProf catalogue matches what `l10n/*.json` says it should be.

Gate 4 exists because gates 1–3 compare the language files only against each other — a
string missing from all of them passes all three and then renders in German everywhere.
Gate 5 exists because gate 4 scans for `t('learning', …)` call sites, and the assistant
has none: it resolves through `translateVirtuProf(lang, key)` against its own bundled
dictionary. 161 of its 343 strings had drifted out of that dictionary while all four
earlier gates stayed green.

**Placeholders must survive translation.** Strings contain markers such as `{n}`, `{date}`
or `%1$s`. They must appear in the translated value too, with the same names — a dropped
placeholder produces a broken string at runtime. Gate 3
(`scripts/check-i18n-placeholders.py`) fails the build if one goes missing, and also
catches a value accidentally written in the wrong script.

Corrections are welcome as a pull request or an issue at
<https://github.com/andremadstop/learning-nc/issues>.

---

## 8. Troubleshooting

**A teacher cannot create courses or pools.** The buttons only appear for members of the
instructor group — see [2.3](#23-letting-teachers-create-courses-and-pools). Nextcloud
administrator rights are neither required nor a substitute for testing: an admin account
always sees the buttons, so it cannot tell you whether a teacher will.

**There is no Manage tab in a pool.** It is shown to the pool's owner and to users the pool
was shared with as **Can edit**. Check whether the yellow
*Student view is on* banner is shown — **Leave student view** brings the tab back — and
whether the pool was shared with you only as **Can view**. See [3.2](#32-add-questions).
Creating a new pool, by contrast, needs the global instructor right
([2.3](#23-letting-teachers-create-courses-and-pools)).

**A pool shows "No questions available for training".** The pool is empty. Add questions
in its **Manage** tab ([3.2](#32-add-questions)).

**The JSON import fails or imports nothing.** Read the preview under the text field and
the reasons listed under the result. Common causes: the file contains several pools (use
**Import pools** in the **Pools** tab, see
[Importing whole pools in the Pools tab](#importing-whole-pools-in-the-pools-tab)), a correct-answer number that counts from 1 under `answer`/`correct` (those count
from 0; use `correct_number` for 1-based), or a marker that names no answer. Questions
without any correct marker are imported with the first answer as correct and a warning.
See [Importing CSV or JSON in the interface](#importing-csv-or-json-in-the-interface).

**The assistant answers in the wrong language.** It follows the language you write in. If
you ask it in Ukrainian it answers in Ukrainian. When it has to guess, it uses the content
language from your personal settings, then your Nextcloud interface language, then the
instance default. Question and answer *content* is separate — see
[7.1](#71-current-state).

**A student sees no content in a course.** The course has no pools attached (3.4), or the
pools are empty. Check the Administration tab.

**A learning mode is missing for students but visible to you.** It is disabled in course
rules (3.7). Instructors see modes that students do not.

**No certificate is issued although the score was reached.** Either the issuer key was
never generated (`learning:cert:init-issuer`), or certification is not enabled for the
course, or a *required* pool is still incomplete (3.4).

**Documents do not appear in the course.** The folder is linked but not scanned — click
**Scan folder** after uploading (3.5).

**AI features are invisible.** Expected, unless AI is enabled globally *and* the
individual user has given consent (section 6).

**The interface is partly German.** Known gap, see 7.2.

**Changes to PHP files have no effect.** PHP opcache — restart the web server after
manual file changes.

---

## Getting help

- Issues and feature requests: <https://github.com/andremadstop/learning-nc/issues>
- Question import workflow: [`docs/import-pool-workflow.md`](import-pool-workflow.md)

The project is actively looking for pilot partners. Reports from a real deployment —
especially a non-German-speaking one — are genuinely valuable; several fixes in recent
releases came directly from user reports.

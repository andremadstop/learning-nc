# Learning — User & Administrator Manual

For **Learning 5.3.1** on Nextcloud 33–35.

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
  runs `occ` commands. Does not automatically see course content.
- **Instructor** — creates courses and pools, enrols students, sees all progress data of
  *their own* courses. A per-course role, not a global one: you are an instructor *of a
  course*, granted when you create it or when another instructor promotes you.
- **Student** — learns, takes tests, sees only their own results.

Everything in Learning is scoped to the course. An instructor of course A cannot see
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
- **Daily Challenge enabled** / **Gamification enabled** — switch off the game elements
  (XP, levels, badges, streaks) if they do not fit your company culture. Worth considering
  for mandatory compliance training.
- **Max import file size (MB)** — raise this before importing large question sets.
- **Audit-Trail — Liveness** and **Support tickets** — operational settings.

If certificates have not been set up yet, this page shows a warning banner telling you to
run `occ learning:cert:init-issuer` once. There is no button for it — it is a server-side
command. See [3.10](#310-completion-and-certificate).

### 2.3 Creating user accounts

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

Open **Learning** in the Nextcloud app bar, then **Pools** in the left sidebar.

Click **"+ Create pool"**. Give it a name and an optional description.

That is all a pool needs. It now exists, empty, owned by you.

**Naming matters more than it looks.** Pool names show up in the course view, in exam
configuration and in compliance reports. Use names that will still make sense to a
colleague in a year: "Occupational safety 2026 — machine operation", not "Test 1".

### 3.2 Add questions

Open the pool, then the **Manage** tab.

Click add-question and fill in the form. The fields are:

| Field | Options | Notes |
|-------|---------|-------|
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

**Use the chapter field consistently.** It is free text, so "Ch. 1", "Chapter 1" and
"1. Safety" become three different chapters. Decide on a scheme before you enter 200
questions. The chapter heatmap — your best tool for spotting where a cohort struggles —
is only as good as this field.

#### Importing questions in bulk

Typing 200 questions through the form is not realistic. Two bulk routes exist:

**JSON import** (recommended for a real rollout):

```bash
sudo -u www-data php occ learning:import-pool-json /path/to/questions.json --user=<your-uid>
```

The JSON schema, and a ready-made prompt for having an AI convert an existing PDF
question catalogue into that schema, are documented in
[`docs/import-pool-workflow.md`](import-pool-workflow.md).

**CSV/JSON import in the UI** — the Manage tab has an import dialog for smaller sets.

To get a template, create two or three questions by hand and export the pool:

```bash
sudo -u www-data php occ learning:export-pool <pool-id> --format=json
```

Now you have a valid file to imitate.

### 3.3 Create a course

Go to **Courses** in the sidebar and create a course. You become its instructor
automatically.

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
student dashboard.

**Course schedule** plans chapters with a **Start date** and a **Target date**.
Students see it as a timeline.

**Talk room** links a Nextcloud Talk room to the course — paste the token
from the Talk URL.

**Maintenance mode** keeps a small daily review portion running after the course ends,
scheduled by the FSRS algorithm. For compliance training this is how knowledge survives
past the test date.

### 3.8 The student's test attempt

A student opens **Learning**, sees their course, and picks a mode.

In **Exam mode** the experience is deliberately exam-like:

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

**Before this works, generate the issuer key once:**

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
| `learning:import-pool-json <file>` | bulk-import question pools |
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

Learning works completely without AI. The AI features require a Google Gemini API key,
and are **off by default**.

When AI is switched on, two gates still apply:

1. The administrator must enable it globally
2. **Each user must individually opt in.** Every user-triggered path that would send data
   to the model checks that consent first. Without consent, the feature returns a
   "consent required" state and no data is transmitted

This is a deliberate GDPR design: opting out leaves you with a reduced but fully working
app, never a broken one.

If your company policy forbids sending content to a third-party model — a normal position
for a manufacturing enterprise — simply leave AI disabled. You lose the AI tutor, question
generation and automatic notes. Pools, courses, exams, certificates, compliance reports
and all simulators are unaffected.

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
4. Add a dictionary for the language to `app/src/l10n/virtuprof-strings.js`.
5. Generate and verify as above.

The check runs four gates: every language has exactly the same key set as `de.json`; each
`.js` matches its `.json`; every value carries the same placeholders as its German source;
and every translatable literal in the source code actually exists in the catalogue. The
last one exists because the first three compare the language files only against each other
— a string missing from all of them passes all three and then renders in German
everywhere.

**Placeholders must survive translation.** Strings contain markers such as `{n}`, `{date}`
or `%1$s`. They must appear in the translated value too, with the same names — a dropped
placeholder produces a broken string at runtime. Gate 3
(`scripts/check-i18n-placeholders.py`) fails the build if one goes missing, and also
catches a value accidentally written in the wrong script.

Corrections are welcome as a pull request or an issue at
<https://codeberg.org/andremadstop/learning-nc/issues>.

---

## 8. Troubleshooting

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

- Issues and feature requests: <https://codeberg.org/andremadstop/learning-nc/issues>
- Question import workflow: [`docs/import-pool-workflow.md`](import-pool-workflow.md)

The project is actively looking for pilot partners. Reports from a real deployment —
especially a non-German-speaking one — are genuinely valuable; several fixes in recent
releases came directly from user reports.

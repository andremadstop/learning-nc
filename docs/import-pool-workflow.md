# Bulk question import from PDF, using an AI as the parser

A workflow for getting question pools out of any instructor's PDF and into Learning.
Use whichever AI you already have (ChatGPT, Claude, Gemini, …) as the parser — no extra
software to install.

## TL;DR

1. Give your AI the PDF plus the prompt below → get JSON back
2. Put the JSON somewhere your Nextcloud can read it
3. Run `occ learning:import-pool-json <file> --user=<uid>` — or, without a server shell,
   upload it in the **Pools** tab with **Import pools** (instructors)
4. The pools show up in the interface

**No server shell?** The same file can be imported in the interface, in one of two ways:

| | **Import pools** (Pools tab) | Question import (a pool → **Manage** → **Import**) |
|---|---|---|
| What it does | creates **new** pools, one per entry under `pools`, owned by you — like the occ command | adds the questions to the pool you opened; its name and description stay |
| Who may | instructors only (Nextcloud administrators and the instructor group, see [`MANUAL.md`, 2.3](MANUAL.md#23-letting-teachers-create-courses-and-pools)) | anyone with edit rights on that pool — owner or **Can edit** share |
| Pools per file | up to 50 | one (a file with several pools is not split up) |
| Rules | exactly those of the occ command — the same code runs | more lenient, see below |
| Limits | 50 pools, 5,000 questions in total, **Max import file size (MB)**, 5 imports per minute | 500 questions, **Max import file size (MB)**, 5 imports per minute |

**Import pools:** **Pools** tab → **Import pools**, choose the file or paste its content. A
preview shows every pool with how many of its questions can be imported and why the others
cannot — it is the server's dry run, so the import then gives the same result. Each pool is
saved on its own: a pool that fails is rolled back completely and the other pools stay. Details in
[`MANUAL.md`, Importing whole pools in the Pools tab](MANUAL.md#importing-whole-pools-in-the-pools-tab).

**Question import:** open a pool you can edit → **Manage** → **Import**, then drop the file
or paste its content (JSON is recognised by itself). The pool's chapter fields,
`exam_key_prefix` and the `_meta` defaults of a one-pool file are applied to the questions
exactly as the occ command applies them. Its limits and the further formats it accepts are
described in [`MANUAL.md`, 3.2](MANUAL.md#importing-csv-or-json-in-the-interface).

The field reference below holds for all three routes. The question import is more lenient
in how answers may be written (for example, it also accepts answers as plain strings with a
separate correct index — `answer`/`correct`/`correct_index` count from 0, only
`correct_number` counts from 1); the occ command and **Import pools** read only answer
objects with `is_correct`.

## JSON schema

One file may contain any number of pools. Minimal example:

```json
{
  "_meta": {
    "source": "Plain-text provenance for later (PDF name, instructor, course number)",
    "handbook_key": "ccna-200-301",
    "handbook_title": "Cisco CCNA 200-301",
    "default_difficulty": "medium",
    "default_question_type": "single"
  },
  "pools": [
    {
      "name": "CCNA 1 — Networking Basics",
      "description": "Optional description of the pool.",
      "chapter_key": "1.1",
      "chapter_title": "Optional: finer-grained chapter title",
      "chapter_order": 1,
      "exam_key_prefix": "ccna-1.1",
      "questions": [
        {
          "original_number": 1,
          "text": "What determines the TCP window size?",
          "explanation": "The window size is set by the receiver — it tells the sender how many bytes it may deliver before it has to wait for an acknowledgement.",
          "question_type": "single",
          "difficulty": "medium",
          "answers": [
            {"text": "The amount of data to be transferred", "is_correct": false},
            {"text": "The number of services in the TCP segment", "is_correct": false},
            {"text": "How much data the receiver can process at once", "is_correct": true},
            {"text": "How much data the source can send at once", "is_correct": false}
          ]
        }
      ]
    }
  ]
}
```

### Field reference

| Field | Required | Type | Notes |
|---|---|---|---|
| `_meta.source` | no | string | Free-text provenance; not shown to learners |
| `_meta.handbook_key` / `_meta.handbook_title` | no | string | Default handbook for every question that has none and whose pool has none |
| `_meta.default_difficulty` | no | string | Difficulty for every question without its own `difficulty` |
| `_meta.format` | no | string | `learning-pool/2` marks an export (see [Round trip](#round-trip)): nothing is filled into its questions from the pool or `_meta`, and a question without `difficulty` stays without one |
| `_meta.default_question_type` | no | string | One of `single`, `multi`, `open`, `pbq` (or the old `true_false`, `multiple`, or `multiple_choice`, read as `single`). Type for every question without its own type — but not when several answers are marked correct (that stays `multi`, also under `pbq`). `pbq` makes the other questions without a type interactive questions |
| `pools[].name` | ✅ | string | Pool name as it appears in the interface; `pool_name` is read as well |
| `pools[].description` | no | string | Multi-line allowed |
| `pools[].chapter_key` | no | string | e.g. `"1.1"` — groups pools in the interface, and is the chapter of every question in the pool that has no `chapter_key` of its own |
| `pools[].chapter_title` | no | string | Human-readable chapter title; filled into questions the same way |
| `pools[].chapter_order` | no | int | Sort order in the interface, a whole number from 1 to 9999; filled into questions the same way. A value outside (0, negative, 10000 or more) is dropped without a message, by occ and by the import in the interface alike |
| `pools[].handbook_key` / `pools[].handbook_title` | no | string | Handbook of the pool's questions that have none; wins over `_meta` |
| `pools[].exam_key_prefix` | no | string | Becomes `<prefix>-q<original_number>` for every question without its own `exam_key` (without `original_number`: the position in the pool, counted from 1) |
| `pools[].questions[].text` | ✅ | string | Markdown allowed — code blocks `\`\`\``, images via `![](...)` |
| `pools[].questions[].answers` | ✅ | array | At least 2 entries, at most 8. The question import (**Manage** → **Import**) drops further answers with a warning, but a correct answer after the eighth, or more than 50 answers, skips the question; the occ command and **Import pools** skip a question with more than 8 answers |
| `pools[].questions[].answers[].is_correct` | ✅ | bool | More than one `true` makes it multiple choice |
| `pools[].questions[].question_type` | no | string | `single` (default), `multi`, `open` (exactly one model answer) or `pbq` — no other type; letter case does not matter, the old names `true_false` and `multiple` are read as `single` and `multi`, and `multiple_choice` is `single` with one correct answer and `multi` with several (`_meta.default_question_type` never applies to it). A question with any other type is skipped with the reason, in the dry run and the import alike; the rest of the pool is imported. `type` is read as well, so a file from `occ learning:export-pool` keeps its types — `multi` stays `multi` with one correct answer too. Without a type, several answers with `is_correct: true` make the question `multi` |
| `pools[].questions[].explanation` | no | string | Shown after the question is answered |
| `pools[].questions[].scenario` | no | string | Scenario / info text shown **before** the question (case, situation, background). Plain text, line breaks kept, max 10 000 characters. The question import (**Manage** → **Import**) also accepts `szenario`, `context` or `info`; occ and **Import pools** read only `scenario` |
| `pools[].questions[].difficulty` | no | string | `easy` \| `medium` \| `hard`; at most 20 characters, a longer value is cut |
| `pools[].questions[].hint` | no | string or object | Hint the learner can open **before** answering (training, Leitner, Smart Queue; never in an exam). Max 2 000 characters. Also read as `hinweis` |
| `pools[].questions[].background_fact`, `key_takeaway` | no | string or object | Shown **after** answering, and in the review of a practice exam (never in or after a counting exam). Max 5 000 / 500 characters. Also read as `background` / `hintergrund` and `takeaway` / `merksatz` |
| `pools[].questions[].feedback_per_option` | no | array | Feedback per answer option, shown after answering next to the chosen option: `[{"option_index": 0, "letter": "A", "verdict": "wrong", "why": "…", "misconception": "…"}]`. `option_index` counts from 0 in the order of `answers` (else `letter`, A = first, else the list order). `why` max 5 000, `misconception` max 1 000 characters. `verdict` is not stored — the answer's `is_correct` decides, and a contradicting verdict is reported. Instead of the list, an answer object may carry `why` (or `feedback`) and `misconception` (or `trap`) itself |
| `pools[].questions[].instructor_note` | no | string | Note for the pool's editors and the course's instructors |
| `pools[].questions[].note_visible` | no | bool | `true` shows the instructor note to learners too; default `false` |
| `pools[].questions[].translations` | no | object | Translations of the question: `{"en": {"text": "…", "explanation": "…", "scenario": "…", "hint": "…", "background_fact": "…", "key_takeaway": "…", "feedback_per_option": [{"option_index": 0, "why": "…", "misconception": "…"}]}}`; `text` is required, the rest optional, same limits as the untranslated fields. Languages: `de`, `en`, `ru`, `ar` (what the app can store); another language, or an entry without `text`, is reported and left out, the question is imported. Read by occ and **Import pools**; the question import (**Manage** → **Import**) ignores it |
| `pools[].questions[].answers[].translations` | no | object | Translations of an answer text: `{"en": "…", "ru": "…"}`, same languages and route as above |
| `pools[].questions[].original_number` | no | int/string | Original number from the PDF — for traceability |
| `pools[].questions[].chapter_key`, `chapter_title`, `chapter_order`, `handbook_key`, `handbook_title`, `exam_key` | no | string / int | Per-question values; they win over the pool and `_meta`. An export writes them per question. `chapter_order` follows the pool rule: 1 to 9999, a value outside is dropped |
| `pools[].questions[].subtype` | for `pbq` | string | Interactive question (`question_type: "pbq"`, no `answers`): `dropdown`, `placement`, `cli`, `cable`, `multi_panel`, `switch_config`, `routing_config`, `diagnostic` or `ranking` |
| `pools[].questions[].pbq_config` | for `pbq` | object | Configuration of the interactive question; its layout differs per subtype — take it from an export |

**Rich feedback languages (GitHub #66):** a field that holds an object of languages
(`{"en": "…", "de": "…"}`) is read in one language: the question's `lang` if it has one,
else `en`, else `de`, else the first. The other languages are reported and not imported — a
pool file is one language. A field over its limit skips the question, as a too long scenario
does.

**Precedence:** a value on the question wins over the pool, the pool wins over `_meta`.
Nothing is invented: a field missing on all three levels stays empty. The one exception is
`difficulty`, which the occ command and **Import pools** — and the question import for
interactive questions — set to `medium` when none is given; the question import leaves a
choice or free-text question without a difficulty. All three routes follow this precedence.

## Prompt template (works with any model)

Hand this to ChatGPT/Claude/Gemini together with the PDF. Works from GPT-4o, Claude Sonnet 3.7
and Gemini 2.0 Flash upwards.

```
Convert a PDF of practice questions into the Learning app's JSON schema.

RULES:
1. Read the entire PDF. Do not drop a single question.
2. If the PDF marks the answers by colour (green, for instance), the marked options are
   is_correct=true.
3. If the answers live in a separate PDF (format "1. B. Explanation..."), take the letter as
   the correct answer and put the explanation in the "explanation" field.
4. Question texts sometimes contain code blocks or tables. Preserve those as Markdown code
   blocks (```) inside the "text" field.
5. If a question needs an image or diagram that is not merely decorative, describe it briefly
   in square brackets, e.g. "[Diagram shows: Client → Internet → Firewall → DMZ]".
6. Question types:
   - exactly one correct answer → question_type: "single"
   - two or more correct answers → question_type: "multi"
   - drag & drop, ordering, matching: SKIP these and list them at the end as omitted.
7. Transcribe the answer texts cleanly — no "A./B./C./D." prefixes, the letters are rendered
   automatically.
8. If the PDF is split into lessons or chapters, emit one pool per chapter with a matching
   chapter_key.
9. Output ONLY the JSON, no prose around it. Strictly valid JSON (UTF-8, no trailing commas).

SCHEMA: see docs/import-pool-workflow.md in the learning-nc repository. In short:
{
  "_meta": {"source": "...", "handbook_key": "...", "handbook_title": "..."},
  "pools": [
    {
      "name": "...",
      "chapter_key": "1.1",
      "chapter_title": "...",
      "exam_key_prefix": "course-1.1",
      "questions": [
        {
          "original_number": 1,
          "text": "...",
          "explanation": "...",
          "answers": [
            {"text": "...", "is_correct": false},
            {"text": "...", "is_correct": true}
          ]
        }
      ]
    }
  ]
}

Finish with a short summary: number of pools, questions per pool, and the skipped questions
with the reason each was skipped.
```

## Running the import

The `file` argument is a path **on the Nextcloud server**, readable by the web-server user, so
copy the JSON there first. Always do the dry run — it runs every check of the real import
(the file, each pool, each question: missing text, too few or too many answers, text too
long, unknown PBQ subtype, …), lists every pool with its number of questions and every pool
or question that would be skipped with the reason, and writes nothing to the database. Its
summary line `Dry-run: N pools, M questions` counts what the real import would store. When no
pool would be imported (or, without `--dry-run`, none was) and there are errors, the command
exits with status 1, so a script can tell.

```bash
# 1. Dry run: runs every check, writes nothing
occ learning:import-pool-json /path/to/pool.json --user=<uid> --dry-run

# 2. The real import
occ learning:import-pool-json /path/to/pool.json --user=<uid>
```

`--user` (or `-u`) is the Nextcloud user ID that will own the new pools. The owner can edit
them in the interface (**Manage** tab) even without the instructor role. Afterwards you can
attach the pools to courses or share them with other users from the interface.

Besides the `pools` format above, the command reads a file with **one** pool and no
`pools` wrapper: a plain list of questions (what an export wrote up to 5.17), or
`{"name": …, "description": …, "_meta": {…}, "questions": [ … ]}` (what an export writes
since 5.18, see [Round trip](#round-trip)). The new pool is named by
`--pool-name`, else by the file's `"name"` (or `"pool_name"`, as in `app/examples/pools/`), else by
the file name without extension:

```bash
occ learning:import-pool-json /tmp/pool-12.json --user=alice --pool-name="Network basics (2nd edition)"
```

`--pool-name` with a `pools` file is refused, because that file names its pools itself.

**What the import does with errors.** Each pool is saved on its own:

- A question that is refused is skipped and listed as `Pool <id> Q<n>: <reason>` — in a dry
  run, or when the pool was not created, `Pool #<position> Q<n>: <reason>`. `<n>` is the
  question's `original_number`, or else its position in the pool counted from 1. The pool
  keeps its other questions.
- A pool without a `name` (`Pool #<n> missing name`, `<n>` counted from 1), with a name over
  200 characters or a description over 2,000 is skipped before anything is written. So is a
  pool whose `handbook_key`/`chapter_key` exceed 64 characters, `handbook_title`/`chapter_title`
  255 or `chapter_order` lies outside 1–9999 (`Pool '<name>': <reason> — skipped`) — in the
  dry run as in the real import.
- A pool that cannot be saved — the pool itself or one of its questions fails in the
  database — is rolled back completely and listed as `Pool '<name>' create failed — skipped`;
  the technical reason goes to the Nextcloud log. The pools before and after it stay.
- A pool whose questions are all skipped is still created, empty. A pool with no questions
  at all is created as well, with the warning `Pool '<name>' contains no questions`.

Running the same file twice creates its pools twice. The occ command has no limit on the
number of pools or questions; **Import pools** in the interface allows 50 pools and 5,000
questions per file. The occ output is always in English.

**Where `occ` lives depends on your installation.** A few common shapes:

```bash
# Package or archive install
sudo -u www-data php /var/www/nextcloud/occ learning:import-pool-json /tmp/pool.json --user=alice

# Docker
docker cp pool.json <container>:/tmp/
docker exec -u www-data <container> php occ learning:import-pool-json /tmp/pool.json --user=alice

# Snap
sudo nextcloud.occ learning:import-pool-json /tmp/pool.json --user=alice
```

## Edge cases and tips

**The AI forgets questions.** Above roughly 50 questions, ask for the output in 2–3 chunks and
merge the pools into one JSON file yourself at the end.

**Images in the PDF.** Ignore purely decorative ones (logos, headers). Content-bearing images
(topology diagrams, Wireshark captures, code screenshots) are worth describing in a sentence —
the app renders Markdown, so `![](url)` works too if you host the images somewhere and link
them.

**Multiple choice in PDFs.** Where the PDF says "(Choose two.)", mark several answers with
`is_correct: true`. The command sets `question_type` to `multi` by itself when it sees more
than one correct answer.

**Drag & drop and ordering questions.** Leave them out of an AI conversion — have the AI list
them among the skipped questions. Both importers do accept interactive questions
(`question_type: "pbq"` with `subtype` and `pbq_config`, see the field reference), but the
configuration layout is not documented here; build such questions in the interface and use
an export as the model if you want to keep them in files.

**Copyright.** An instructor's original PDF, and the JSON derived from it, is usually
copyrighted material. **Do not commit it to a public repository** and do not share the pool
publicly.

**More than one attempt.** If the first result is incomplete, show the model which question is
missing, or ask for the output in batches. Context windows differ — Claude offers 200k, GPT-4o
128k.

## Round trip

`learning:export-pool --format=json` (and **Export JSON** in a pool's **Manage** tab) write
one pool as a **pool document in format `learning-pool/2`** (since 5.18):

```json
{
  "_meta": {"format": "learning-pool/2"},
  "name": "Network basics",
  "description": "…",
  "handbook_key": "ccna", "handbook_title": "…",
  "chapter_key": "3", "chapter_title": "…", "chapter_order": 4,
  "questions": [ … ]
}
```

Per question it carries `text`, `type`, `scenario`, `explanation`, `difficulty`,
`chapter_key`, `chapter_title`, `chapter_order`, `handbook_key`, `handbook_title`,
`exam_key`, `instructor_note`, `note_visible` (only when `true`), `hint`, `background_fact`,
`key_takeaway`, `feedback_per_option` (with `option_index`, `letter` and the `verdict` from
the answer key), `translations` and the `answers` with `is_correct` and their own
`translations`; an interactive question carries `subtype` and `pbq_config` instead of
answers. Empty fields are left out.

**Without losses** — through occ or **Import pools**, the export comes back as the same pool:
the pool's name, description and chapter fields, and every question field listed above.
What a pool export does not carry, on purpose:

- **Attached images** (`image_path`): the path points into the app data of the instance that
  exported it; an import never takes a path from a file. Attach images again by hand.
- **Audio, subtitles and cues** (GitHub #66): they travel in their own media bundle, see
  below (`--media-dir`).
- **Review state** (published or draft, reviewer): an imported question is published.
- **Owner, shares, course assignments** and course settings of the pool such as its exam
  scope (number of exam questions, exam time, required pool) — those belong to the course
  (**Courses** → the course → pools), not to the pool.
- **Learner data**: progress, answers, Leitner boxes.

The answers keep their order; their internal positions are renumbered from 0, and
`option_index` follows them.

**Format 1** — the plain list of questions that exports up to 5.17 wrote — is still read by
every route, with the rules of a hand-written file (pool and `_meta` defaults, `medium` for a
question without difficulty). In a `learning-pool/2` file every question carries its own
values: a question without chapter stays without chapter even when the pool has one.

It goes back in by any of three routes:

- **As a new pool in the interface:** **Pools** tab → **Import pools** (instructors). The new
  pool takes the name in the file (an older export: the uploaded file's name, `pool-12.json`
  becomes `pool-12`; pasted text gives *Imported pool*).
- **Through occ:** `occ learning:import-pool-json pool-12.json --user=<uid>` creates a **new**
  pool from it, named as in the file unless `--pool-name="…"` says otherwise; delete the old
  one by hand if you no longer need it.
- **Into an existing pool:** **Manage** → **Import** in any pool you can edit; the questions
  are added to that pool, which keeps its own name and description. This route does not read
  `translations`, `instructor_note` and `note_visible`.

That also makes an export the best template for your own files. CSV
(`--format=csv`) is not a round trip: it carries only the text, the answers with one correct
answer (or the model answer of an open question) and the explanation.

A typical edit cycle:

- export the pool (`occ learning:export-pool <poolId> --format=json --output=pool.json`, or
  **Export JSON** in the interface)
- edit the JSON — add explanations, fix typos
- re-import it by one of the three routes above

## Audio and cues per question (media bundle)

Audio, subtitles and the cues for the player come in a separate **media bundle** after the pool
is imported. Questions are matched by `exam_key`, so the pool's questions need one.

```
bundle/
  manifest.json                                   {"format": "learning-question-media/1", "questions": [...]}
  <dir>/intro-<lang>.mp3   + intro-<lang>.json    timeline [{role, text, start, end}] in seconds
  <dir>/dialog-<lang>.mp3  + dialog-<lang>.json
  <dir>/cues.json                                 keywords per line, contrast wrong/right, key takeaway
  <exam_key>/opt-<i>-<lang>.mp3 + .json           format /2 only: spoken explanation of option i, {duration, role, text}
```

Format `learning-question-media/2` is format `/1` plus a spoken explanation per answer option:
an entry of `feedback_per_option` may carry `"audio": {"<lang>": "opt-<option_index>-<lang>.mp3"}`,
a path relative to the question's folder `<exam_key>/`, with a `.json` of the same name next to
it (`{duration, role, text}`, the text is shown as the subtitle). A `/1` bundle is read exactly
as before; `audio` inside a `/1` bundle is reported and ignored.

Per question the manifest lists `exam_key`, optionally the text fields of the pool JSON (`hint`,
`background_fact`, `key_takeaway`/`merksatz`, `feedback_per_option`), `tracks`
(`[{kind: intro|dialog, lang, file, timeline}]`) and `cues`. Text fields given as `{en, de, …}`
are taken in the question's language. `option_index` counts the question's options in their
stored order, and a `letter` given next to it must match (A = 0). Duplicate option texts and
fewer than four options are fine.

```bash
php occ learning:import-question-media <poolId> /path/to/bundle --dry-run   # check, write nothing
php occ learning:import-question-media <poolId> /path/to/bundle
```

- Files go into the app's own storage (app data), not into anyone's files. Paths in the
  manifest are relative and limited to `a-z 0-9 . _ / -`. `..` and symlinks are refused, and
  so is an mp3 over 8 MiB or a JSON file over 256 KiB.
- Running it again changes nothing. The manifest is the truth for a matched question: tracks
  it no longer lists are removed. A listed file that is missing keeps the stored copy. The
  option voices are only touched by a `/2` entry that has `feedback_per_option`; a `/1` bundle
  (or an entry without that list) leaves them as they are.
- The report lists matched questions, unknown or ambiguous `exam_key`s and missing or refused
  files. The exit code is not 0 if any of those occurred.
- Delivery: the intro goes to everyone who can see the pool. The dialog and the cues explain
  the answer, so learners get them only with the answer response in training, Leitner and
  Smart Queue. The option voices say which option is right, so they follow the dialog: before
  answering a learner learns nothing about them, not even which options have one; the answer
  response lists them per answer. During a counting exam a learner gets no media at all. Media
  are deleted together with their question or pool.
- Export: `occ learning:export-pool <poolId> --media-dir=<new or empty dir>` writes the pool's
  media as a bundle that this command reads back — format `/2` when the pool has option voices,
  `/1` otherwise. Questions with media but without `exam_key` are reported and left out.
- Generic examples: `app/tests/fixtures/question-media/` (`bundle/` is `/1`, `bundle-v2/` is `/2`).

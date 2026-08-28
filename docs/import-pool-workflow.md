# Bulk question import from PDF, using an AI as the parser

A workflow for getting question pools out of any instructor's PDF and into Learning.
Use whichever AI you already have (ChatGPT, Claude, Gemini, …) as the parser — no extra
software to install.

## TL;DR

1. Give your AI the PDF plus the prompt below → get JSON back
2. Put the JSON somewhere your Nextcloud can read it
3. Run `occ learning:import-pool-json <file> --user=<uid>`
4. The pool shows up in the interface

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
| `pools[].name` | ✅ | string | Pool name as it appears in the interface |
| `pools[].description` | no | string | Multi-line allowed |
| `pools[].chapter_key` | no | string | e.g. `"1.1"` — groups pools in the interface |
| `pools[].chapter_title` | no | string | Human-readable chapter title |
| `pools[].chapter_order` | no | int | Sort order in the interface |
| `pools[].exam_key_prefix` | no | string | Becomes `<prefix>-q<original_number>` for every question |
| `pools[].questions[].text` | ✅ | string | Markdown allowed — code blocks `\`\`\``, images via `![](...)` |
| `pools[].questions[].answers` | ✅ | array | At least 2 entries |
| `pools[].questions[].answers[].is_correct` | ✅ | bool | More than one `true` makes it multiple choice |
| `pools[].questions[].question_type` | no | string | `single` (default) or `multi`. Falls back to `multi` when several answers are `is_correct: true` |
| `pools[].questions[].explanation` | no | string | Shown after the question is answered |
| `pools[].questions[].difficulty` | no | string | `easy` \| `medium` \| `hard` |
| `pools[].questions[].original_number` | no | int/string | Original number from the PDF — for traceability |

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
copy the JSON there first. Always do the dry run — it parses and validates without writing
anything to the database.

```bash
# 1. Dry run: parses and validates, writes nothing
occ learning:import-pool-json /path/to/pool.json --user=<uid> --dry-run

# 2. The real import
occ learning:import-pool-json /path/to/pool.json --user=<uid>
```

`--user` (or `-u`) is the Nextcloud user ID that will own the new pools. Afterwards you can
attach the pools to courses or share them with other users from the interface.

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

**Drag & drop and ordering questions.** Not supported by the importer — have the AI list them
among the skipped questions. You can rebuild them by hand as multiple choice ("Which steps
belong to the correct sequence?") or author them in the interface.

**Copyright.** An instructor's original PDF, and the JSON derived from it, is usually
copyrighted material. **Do not commit it to a public repository** and do not share the pool
publicly.

**More than one attempt.** If the first result is incomplete, show the model which question is
missing, or ask for the output in batches. Context windows differ — Claude offers 200k, GPT-4o
128k.

## Round trip

`learning:export-pool --format=json` emits the same format, so you can:

- export an existing pool (`occ learning:export-pool <poolId> --format=json --output=pool.json`)
- edit the JSON — add explanations, fix typos
- re-import it with `learning:import-pool-json`, which creates a **new** pool
- delete the old one by hand

The format round-trips.

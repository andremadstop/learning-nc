# Testing

Learning is tested in four layers. The lower layers are fast and run on every commit. The upper layers start a real Nextcloud and run every night, and before every release.

```
            Gate 4  Integration, lifecycle                    real Nextcloud 33/34/35,
                    (PostgreSQL and MariaDB)                  nightly + before release
                    SQL columns                               Nextcloud 33 + PostgreSQL, pre-push
          Gate 3  Browser: Playwright E2E, Persona-Gate       real Nextcloud, nightly / by hand
        Gate 2  (retired, replaced by Gate 4)
      Gate 1  Static analysis + unit tests                   every commit, push, pull request
```

Every PHP gate runs in a throwaway Docker container on a clean snapshot of the code: the git index (what `git commit` would record) or a git ref. It never runs on the working copy. You do not need PHP installed locally, only Docker.

## Where each check runs

| Check | pre-commit hook | pre-push hook | CI (every push/PR) | Nightly |
|---|:-:|:-:|:-:|:-:|
| ESLint (0 errors; warnings allowed) | ✓ | ✓ | ✓ | |
| Vitest | ✓ | ✓ | ✓ | |
| Coverage report, JS and PHP (artifact, no threshold) | | | ✓ | |
| Property tests with a random seed (fast-check) | | | | ✓ |
| Production build (`npm run build`) | | | ✓ | |
| i18n gates (7, see below) | ✓ | ✓ | ✓ | |
| PHPStan level 5 + PHPUnit (`gate-container.sh`) | ✓ | ✓ | ✓ | |
| Secret scan (hard-coded keys and passwords) | | ✓ | | |
| OpenAPI spec up to date (`openapi-spec.sh --check`) | | ✓ | ✓ | |
| SQL columns against a freshly installed schema | | ✓ | | |
| Schema-drift self-test (`schema-drift.test.sh`) | | | ✓ | |
| Forbidden names (trademark/publicity check) | | ✓ | | |
| Umlaut check (`ae`/`oe`/`ue` in German UI text; warning only) | | ✓ | | |
| Repo hygiene (no internal working documents in the tree) | | ✓ | | |
| Integration tests, NC 33/34/35 × PostgreSQL/MariaDB | | | | ✓ |
| Lifecycle probe, NC 33/34/35 × PostgreSQL/MariaDB | | | | ✓ |
| Persona sweep, NC 33 + PostgreSQL, gamification off | | | | ✓ |
| Visual comparison with the previous nightly persona run (report only) | | | | ✓ |
| Playwright E2E suite (`app/tests/e2e`) | | | | |

The last row is not automated: the E2E suite runs locally (see Gate 3).

- **CI** ([`.github/workflows/ci.yml`](.github/workflows/ci.yml)) runs on every branch push and every pull request.
- **Nightly** ([`.github/workflows/nightly.yml`](.github/workflows/nightly.yml)) runs at 02:17 UTC from `main`. It can also be started by hand for any branch (`workflow_dispatch`). Logs, the persona report and its screenshots are kept as artifacts for 14 days.
- **The hooks** check what is being committed or pushed, not the working copy. A gate that cannot run (for example because Docker is not available) blocks the commit or push. It is never reported as skipped. To enable them in a clone:

  ```bash
  git config core.hooksPath .githooks
  ```

CI and the workflows use no secrets. Signing, releases and the App Store upload stay on the maintainer's machine.

## Gate 1: static analysis and unit tests

From the repository root, after `npm --prefix app ci`:

```bash
(cd app && npx eslint src/ --quiet) # ESLint, errors only
npm --prefix app run test                          # Vitest
npm --prefix app run build                         # production bundle
./scripts/check-i18n-parity.sh                     # i18n gates
./scripts/gate-container.sh all                    # PHPStan L5 + PHPUnit on the git index
./scripts/gate-container.sh all --ref HEAD         # ... or on a commit
./scripts/gate-container.sh --self-test            # proves an error and a skipped test turn the gate red
./scripts/gate-container.sh coverage               # PHPUnit with a coverage report (see Coverage reports)
```

`gate-container.sh` runs a pinned `nextcloud:33` image (by digest) and installs PHP dependencies from the versioned `composer.lock`. PHPUnit runs with `--fail-on-skipped --fail-on-incomplete`, because a skipped test is a check that never ran. The last output line is always `GATE-RESULT phpstan=<ok|fail> phpunit=<ok|fail>`.

The interface is translated into six languages (de, en, fr, ru, ar, uk). `check-i18n-parity.sh` runs seven checks:

1. Every language has the same keys.
2. `l10n/<lang>.js` matches `l10n/<lang>.json`.
3. Placeholders and script are correct for each language.
4. Every string the source code uses exists in the catalogue (source → catalogue).
5. The VirtuProf catalogue (`src/l10n/virtuprof-strings.js`) is up to date.
6. The consent texts are complete in all six languages and name the recipient.
7. No translation is simply the English text (`check-i18n-untranslated.py`), apart from product names, abbreviations and French cognates. The keys that were untranslated when this check came in are listed in `scripts/i18n-untranslated-baseline.json`; that list may only shrink.

The first three compare the language files with each other. The fourth checks the source code against the catalogue: a string missing from every language looks consistent to the first three checks.

## Gate 2: retired

Gate 2 was an API test suite (`scripts/test-api.sh`, plus a smoke test). It ran against a shared development instance that no longer exists. The scripts are still in the repository but have no target. Gate 4 replaces them: it installs a fresh Nextcloud for every run.

## Gate 3: browser

### Playwright E2E suite

`app/tests/e2e/` covers critical flows: exam mode, public certificate verification, PBQ question types, onboarding, accessibility spot checks and the skin picker. It runs against a local Nextcloud started with `docker-compose.e2e.yml`. The steps are in [`app/tests/e2e/README.md`](app/tests/e2e/README.md). The suite is not part of CI.

### Persona-Gate

The gates above read code. None of them clicks through the app from the outside. One operator did exactly that, with gamification, AI and Leitner switched off, and reported 24 issues in a row (#35–#61). The Persona-Gate plays that scenario on a fresh instance.

**Run it:**

```bash
./scripts/persona-sweep/run.sh            # up → sweep → report → down
GAMIFICATION=on ./scripts/persona-sweep/run.sh
REF=<git-ref> KEEP=1 ./scripts/persona-sweep/run.sh   # another snapshot; leave the instance running
./scripts/persona-sweep/run.sh down       # remove a kept instance
```

It needs Docker and Chromium. `PERSONA_CHROMIUM` points at the browser binary. The Playwright package comes from `app/node_modules`.

**The scenario:**

- Nextcloud 33 and PostgreSQL 16, pinned by digest, with an English interface.
- By default gamification, AI and Leitner are off: the mandatory-training scenario. `GAMIFICATION=on` seeds an IT course as before #36 instead: gamification on, every course mode on (Leitner included), and AI on with an Ollama connection that nothing answers (no external provider). It runs the same detectors; the stations that only exist with these modes on are added. `sweep` takes the mode of the running instance.
- The app comes from `git archive $REF`, so the committed bundle is what gets clicked, the same as what ships.
- A signing key is created. One course has two required pools of 8 questions each, certification at 75 %, and a group of three learners enrolled. In the default mode it is created with the course profile "Mandatory training with certificate" (Leitner, tools, social and game modes off); the seed fails if the profile leaves any of them on. With `GAMIFICATION=on` it is created with the profile "IT exam preparation" (every mode on).

**Roles (personas):** administrator, instructor, two learners, and the administrator in student view. Each persona opens every main tab and sub-tab of the course, the course list, the pool window, and the admin and personal settings. At each station it clicks up to 30 visible buttons. Destructive or irreversible actions are never clicked: delete, remove, revoke, withdraw, unenrol, archive, reset, import or upload, generate or rotate keys, start an exam. A learner passes both exams first and opens, prints and downloads the certificate.

**What each station records:**

- a screenshot
- app requests answering with 400 or above
- console errors and page errors
- an empty main area
- a success toast without a successful request before it. The toast detector checks itself at the start of each run.
- German words in the English interface
- accessibility with axe-core (pinned devDependency in `app/node_modules`) in `#app-content`: every contrast violation and every critical or serious WCAG 2.1 A/AA violation, reported as kind `a11y`. A self-test station proves at the start of each run that the contrast check reports a grey-on-grey element.

Findings that are the app working as intended are listed separately and not counted. The gate reports findings, it does not fail on them. A run fails only when the click-through itself breaks, for example when an exam question has no seeded answer or no compliance report is found; a broken self-test shows up as a `detector` finding. The output goes to `scripts/persona-sweep/out/gamification-<off|on>/` (gitignored): `report.md`, `findings.json`, `stations.json` (the input for the fresh-eyes review), `shots/` and `downloads/`. `PERSONA_STATIONS=<regex>` sweeps only the matching stations and writes `*-filtered` files next to them.

**Visual comparison of two runs.** `scripts/persona-sweep/visual-diff.mjs` compares the screenshots of two runs station by station (pixelmatch, pinned devDependency). It matches them by persona and station, not by file name, because the running number in `admin--018-top-courses.png` shifts when a station is added. The report lists each station as changed (with the share of changed pixels and a diff image), resized, new, gone or unreadable:

```bash
node scripts/persona-sweep/visual-diff.mjs <baseline-run> <current-run> [--out <dir>] [--threshold 0.1]
# e.g. a kept run against the current one:
node scripts/persona-sweep/visual-diff.mjs /tmp/persona-before/gamification-off scripts/persona-sweep/out/gamification-off
```

The output goes to `scripts/persona-sweep/out/visual-diff/` (`report.md`, `report.json`, `diff/`). No reference images are committed. The nightly job compares against the screenshots of the last successful nightly run of the same branch, adds the counts to the job summary and the report to the artifact. This is groundwork, not a gate: the script always exits 0, and a changed station is a lead to look at. Approved references and a failing check come later, once the screenshots are stable from run to run (for example dates, avatars, animations).

**Fresh-eyes review (by hand).** A sweep finds what can be measured. It does not find "what is this for?" or "nobody without an IT background understands this". For that, a language model gets only the screenshots and the visible text of each station. It sees no code, no documentation and no issue list, and it answers as the person the course is built for. That person is an administrator of a small organisation with no IT background, who has to set up an annual mandatory training with certificates. For every station it answers:

1. Do I understand what this is for?
2. Does it belong here, given the settings (gamification, AI, Leitner) and the current role? With gamification off, as in a mandatory training, points, leaderboards and network simulators in a fire-safety course should not be visible. With gamification on, as in an IT course, the question is about regressions: is something missing, broken or unreadable?
3. Is it readable? This covers contrast, cut-off text, unexplained jargon, internal identifiers or placeholders, and German words in the English interface.
4. What would I report as an issue?

It also gives an overall summary: would it set up the training with this app, and what holds it up most? Its findings are hypotheses. They are checked against the code before they become a fix or an issue. The procedure and the prompt are in [`scripts/persona-sweep/FRESH-EYES.md`](scripts/persona-sweep/FRESH-EYES.md): the reviewer runs in a directory that holds only `stations.json` and the screenshots, once per mode. This review is a manual step and not part of CI.

## Gate 4: integration, lifecycle, SQL columns, schema drift

All four install a real Nextcloud in throwaway containers, with no OCP stubs. Containers, volumes and networks are removed after every run. A failed cleanup fails the run.

```bash
ENGINE=pgsql ./scripts/nc-integration.sh            # app/tests/Integration on the git index
ENGINE=mysql ./scripts/nc-integration.sh --ref HEAD # ... on MariaDB, for a commit
ENGINE=pgsql ./scripts/lifecycle-probe.sh
ENGINE=mysql PROBE_REF=<git-ref> ./scripts/lifecycle-probe.sh
./scripts/schema-dump.sh /tmp/schema.txt && SCHEMA_FILE=/tmp/schema.txt python3 scripts/check-sql-columns.py
./scripts/schema-drift.sh live-schema.txt --fresh-ref v<installed>   # live instance vs fresh schema vs entities
```

- **Integration** (`nc-integration.sh`) runs `app/tests/Integration` with a pinned, checksum-verified PHPUnit PHAR against Nextcloud 33. PostgreSQL 16 is the default; `ENGINE=mysql` uses MariaDB 10.11. A local mock LLM server stands in for AI providers. `INTEG_NC_IMAGE` selects another Nextcloud image; the nightly run uses 34 and 35.
- **Lifecycle** (`lifecycle-probe.sh`) runs three scenarios per database:
  - a fresh install
  - an upgrade from the 5.5.1 release with seeded legacy data
  - an upgrade from a bare 5.5.1
  - an upgrade from each recent release in `RECENT_BASES` (default 5.13.0 and 5.14.0) with course videos, document rows, progress and read confirmations, read back through the app's mappers. The upgraded schema must equal the fresh one, and `schema-drift.py` must find no breaking drift. A base that is not older than the working state fails, because Nextcloud runs migrations only on a version change; `PROBE_BUMP_VERSION=<x.y.z>` raises the version in the probe's copy of `info.xml` to test unreleased migrations. `RECENT_TARBALL_DIR=<dir>` reads the tarballs from `<dir>/<v>/learning-<v>.tar.gz` instead of downloading them; the pinned checksums still apply.

  The first three scenarios then check the audit hash chain, a real compliance event, a translation round-trip, the AI-settings migration and the member repair step. `LIFECYCLE_NC_IMAGE` selects the Nextcloud image. This probe exists because Nextcloud runs only `changeSchema()` on a first install, never `postSchemaChange()`: data written there is missing on every new installation.
- **SQL columns** (`schema-dump.sh` + `check-sql-columns.py`) checks the column names in raw SQL against the schema of a fresh install. PHPStan cannot see a wrong column inside a string. This check runs in the pre-push hook.
- **Entities cover the schema** (`app/tests/Integration/EntityCoversSchemaTest.php`, part of the integration suite) runs every column of the freshly installed schema through `Entity::fromRow()` for each mapper that selects `*`. A column without an entity property makes every such read throw ("… is not a valid attribute").
- **Schema drift** (`schema-drift.sh`) compares the learning tables of a live instance with a fresh schema and with the entities in `lib/Db`. Old installs can carry columns no fresh install has: 5.11.0 removed `Pool::$visibility`, and every full pool read failed on installs that started on 1.x/2.x, which still have `learning_pools.visibility` (fixed in 5.14.1). The live side is a text file you produce on the instance; the script never connects to a database. Exit 1 means breaking drift: a live column on an entity table without a property, a missing column, or a missing table the code still uses. Extra tables and columns, name lookalikes (`q_translations` / `qst_translations`) and properties without a column are printed as notes.

### Before every release: live schema against the fresh schema

Run on the production instance (or a copy of its database) before each release, with the release candidate checked out:

```bash
# 1. On the instance: dump the learning tables (read-only). Replace oc_ with the dbtableprefix of config.php.
#    PostgreSQL:
psql -U <dbuser> -d <dbname> -tA -F '|' -c "select table_name, column_name from information_schema.columns
  where table_schema = current_schema() and table_name like 'oc\_learning\_%' order by 1, 2" > live-schema.txt
#    MySQL / MariaDB:
mysql -u <dbuser> -p -N -B -e "select table_name, column_name from information_schema.columns
  where table_schema = DATABASE() and table_name like 'oc\_learning\_%' order by 1, 2" <dbname> | tr '\t' '|' > live-schema.txt

# 2. Locally, with the release candidate in the git index: the fresh schema of the version the
#    instance runs NOW (the tag of `occ app:list | grep learning`), the entities of the candidate.
./scripts/schema-drift.sh live-schema.txt --fresh-ref v<installed version>   # schema-dump.sh in Docker, ~2 min
#    after the update, optional: the instance must now match the candidate's own fresh schema
./scripts/schema-drift.sh live-schema-after.txt                              # fresh schema of the git index
```

Before the update, the instance still runs the previous release, so it cannot have the columns and tables the candidate's migrations add. Compared with the candidate's own fresh schema, each of them would be a `MISSING COLUMN` or `MISSING TABLE`, and every release with a migration would fail for no reason. Compared with the fresh schema of the installed version, a missing column or table is real: a migration of an earlier release did not run on that instance. The candidate's entities are what the instance will read with after the update; a live column they have no property for is the 5.14.1 case. Columns the candidate adds appear only as `PROPERTY WITHOUT COLUMN` notes.

Exit 1 of step 2 blocks the release. A live-only column needs a property on its entity (read and ignored, like `Pool::$visibility`) or a migration that drops it. If the candidate itself drops that column in a migration, the finding is expected: check that the migration covers exactly that column. Exit 1 of the run after the update means a migration of the candidate did not run.

Right after a release, pin its tarball's sha256 in `RECENT_SHA256` of `scripts/lifecycle-probe.sh`. Add the release to the default `RECENT_BASES` only in the commit that raises `info.xml` to the next version: until then the working state has the same version, Nextcloud would run no migration, and `recent_<v>_version_jump` fails (the nightly run sets no `PROBE_BUMP_VERSION`).
- **Uninstall on both databases**: changes to raw SQL or transaction handling are also checked with `scripts/db-uninstall-probe.php`, once with `PROBE_PLATFORM=mysql` and once with `PROBE_PLATFORM=pgsql`. Instructions are in the file header. Each database has caught bugs the other could not: MariaDB treats double-quoted identifiers as strings, and PostgreSQL silently turns a `COMMIT` in a failed transaction into a rollback.

## Contract tests: OpenAPI and frontend calls

`app/openapi.json` describes every route of `appinfo/routes.php`: path, method, path and query parameters with the types from the method signatures, and whether the route needs the request token. It is generated from the controllers by [nextcloud/openapi-extractor](https://github.com/nextcloud/openapi-extractor), pinned in `app/composer.lock`. Every controller carries `#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT)]`; without it the extractor ignores routes that are not OCS routes.

```bash
./scripts/openapi-spec.sh --write          # regenerate app/openapi.json from the git index
./scripts/openapi-spec.sh --check --ref HEAD   # what CI and pre-push run
./scripts/openapi-spec.sh --write-baseline     # after typing methods: drop their lines from the error baseline
```

`--check` fails when

- the committed `app/openapi.json` differs from what the extractor generates,
- a route of `appinfo/routes.php` is missing from the spec, or
- the extractor fails with anything other than its final error count.

**Known gap: response bodies.** Most controller methods have no typed `@return DataResponse<...>` annotation. The extractor reports each one as an error, writes the spec anyway, and ends with "Encountered N errors". The script accepts that only when the N error lines (`Error: <controller>#<method>: <message>`) are exactly the lines of `scripts/openapi-error-baseline.txt`. The list may only shrink: a commit that documents a method removes its lines (`scripts/openapi-spec.sh --write-baseline`, which refuses to add one), and a new undocumented method makes the check fail with its `controller#method` printed. Because lines are compared, not only their count, one fixed method cannot hide a new untyped one. Until the responses are typed, the spec checks requests (route, method, parameters, CSRF), not response keys and types.

**Frontend contract test** (`app/tests/unit/apiContract65.test.js`, part of Vitest). It parses every file in `app/src` with the real parser (Babel through `@vue/compiler-sfc`) and evaluates the URL argument of each call site: literals, `+`, template strings, `generateUrl()`, local and imported constants, URL-builder functions, and `this.x` computed properties and methods. Then it checks:

- **XHR** (`axios.get/post/put/patch/delete`): the route exists in `openapi.json` with that method. A wrong path or a `PUT` where the controller only has `POST` turns it red. Server-sent event streams count here as `GET`: `createSseClient(url)` (duel, gameshow) and `new EventSource(url)`, which send the request token as the `requesttoken` query parameter.
- **Navigation** (`window.location`, `window.open`, `<a>.href = …`, `href`/`src` attributes and bindings in templates): the browser sends no request token, so the route must be `GET` and `#[NoCSRFRequired]`. That is the bug behind four CSRF fixes in 5.12.0 (#36, #41, #45).
- **What it cannot evaluate** (data URLs, URLs from API responses, blob downloads) must be listed in `UNRESOLVED_ALLOWED` with a reason. An entry that no longer occurs fails the test, so the list only shrinks.
- **The scanner itself**: it must find as many `axios.<method>(` calls as a plain text search does, and planted call sites (unknown route, wrong method, CSRF download through an imported URL builder, an SSE stream to an unknown route) must each be reported.

A URL with an unknown part is checked with that part as a wildcard segment. Request bodies are not checked yet: the spec types the parameters of the method signature, but many controllers read the body with `getParam()`.

The spec is a development artefact. It is not in the release tarball (`app/Makefile`, `release_tracked`).

## Property-based tests

`app/tests/unit/importRoundtrip65.property.test.js` uses [fast-check](https://fast-check.dev/) (pinned devDependency) on the pool import readers. Instead of chosen examples it generates arbitrary text in every field: quotes, delimiters, line breaks, formula prefixes, Unicode, and the readers' own keywords. It checks four properties:

1. `csvRecords()` reads back every table an independent RFC 4180 writer produced.
2. A pool exported as CSV, in the exporter's format (`fputcsv`, formula guard), imports as the same questions.
3. A pool exported as JSON (single, multi, open and PBQ questions) imports as the same questions, with the metadata the reader stores: difficulty, PBQ subtype, handbook, exam key and chapter (key, title, order). Explanation and scenario are written too but not compared, because the port does not carry them on the JSON path; `PoolExportRoundTripTest` checks them, and `pbq_config`, on the PHP importers.
4. Neither reader throws on any input, including arbitrary JSON built from the import keys. Each answers with an error or a summary.

These properties run against the JavaScript port of the server's readers (`src/utils/importPreview.js`), not against the PHP importer. The port follows the PHP readers rule for rule, and the shared fixtures check both against each other.

CI runs a fixed seed (`FC_SEED`, default 65065), so a red run can be reproduced. The nightly workflow runs a random seed with 5000 runs and prints the seed:

```bash
FC_SEED=<seed> FC_RUNS=5000 npx --prefix app vitest run tests/unit/importRoundtrip65.property.test.js
```

**Findings (fixed in 5.18.0).** The CSV format needs no column names, so the reader has to guess. The property found three cases where it guessed wrong on an export:

- A first question that mentions "question" and "answer" (or "Frage" and "Antwort") was dropped as a header. Now a header is a first record that names the columns **and does not itself import as a question** (every open-form record imports, so there the first field decides: a column name — at most four words, no `?` `.` `!` `:` — keeps `question,model_answer,open`, `Question text,Model answer,open` or `Fragen,Antworten,open` a header, as before).
- Answers with an unquoted `;` (for example `cd;ls;pwd`) switched the reader to the `;` delimiter. Now the delimiter is the one under which **more records import as questions**; only on a tie does the old rule decide (more fields in the first data record, comma on a tie).
- A numeric explanation (for example `2`) was read as the number of the correct answer, also where that reading cannot import (eight answers: the number field made a ninth). Now, when the reading of every earlier version (last field as the number) does not import, "answers, number, explanation" is taken if that one imports; with a header that names the correct column (whole names only, not `Lösungsweg` or `Solution notes`), the number comes from that column.

Each finding is a normal example test in the property file and in `ImportControllerCsvTest.php` (red on the old readers, verified). The property no longer excludes the header and delimiter cases.

**Ambiguity, warned.** Without a header, "answers …, 1, number" and "answers …, number, explanation" are the same text: the export of `{answers: [a, b, "1"], correct: 2}` and of `{answers: [a, b], correct: 1, explanation: "2"}` are both `Q,a,b,1,2`. No reader can return both. Rows that imported before 5.18.0 must be read as before, so the reader keeps the old reading (last field as the number) whenever it imports, and **warns** for every row where the other reading would also import ("Line N: Ambiguous numbers — read as correct answer … without explanation; if the last number is an explanation, add a header row …"), in the preview and in `ImportController::importCsv` alike. The property models both sides: a question without explanation whose last answer is a number from 1 to the number of answers before it comes back unchanged **with** that warning; a question whose explanation is a number from 1 to the number of answers plus one (at most eight fields between question and explanation) comes back read the old way, with the warning, and is the only case left out of the round trip; every other question comes back unchanged and **without** the warning. Example tests in the property file and `ImportControllerCsvTest.php` pin the warning and its absence (one reading only, or a header that decides). The cure for a numeric explanation is on the writing side: an export with a header row that names the correct column (not done yet).

**Differential run against 5.17.0.** Done once for this change, not part of the suite: the 5.17.0 preview reader (`git show 785dce66:app/src/utils/importPreview.js`) and the current one read 120,000 generated files (exported pools and raw rows with empty fields and small numbers; no header, no `;`, no header words, no field `0`, so the deliberate changes stay out) on three seeds. About 216,000 records that the old reader imported came back identical; the only records that import now and did not before are rows with eight answers and a numeric explanation.

**Regression fixture.** `app/tests/Fixtures/import-csv-regression.json` records how the readers read the shipped examples (`app/examples/*.csv`) and 31 hand-written CSV texts (among them the manual's example and six header variants), recorded from the readers before the fix; the last one (numeric answers without a header) was recorded later from the 5.17.0 reader, with the ambiguity warnings as the only addition. `importCsvRegression.test.js` (preview) and `ImportCsvRegressionTest.php` (`ImportController::importCsv`) must read every one of them exactly as recorded. A deliberate change of a reading means changing the fixture in the same commit, with the reason in the commit message.

## Coverage reports

Coverage is reported, not enforced: there is no threshold. A number to aim for would reward tests that execute code without checking it; the rule below (a fix comes with a test that fails on the old code) is the bar.

```bash
npm --prefix app run test:coverage                 # Vitest + v8 → app/coverage/js (html, lcov, summary)
./scripts/gate-container.sh coverage               # PHPUnit + pcov on the git index → app/coverage/php
./scripts/gate-container.sh coverage --ref HEAD    # ... on a commit
```

The PHP mode is the PHPUnit gate with the `pcov` extension. It is built in the throwaway container from a pinned PECL tarball whose checksum is verified. The mode fails exactly when the PHPUnit gate fails. CI uploads both reports as artifacts (`coverage-js`, `coverage-php`, kept for 14 days) and writes the PHP summary into the job summary. The hooks do not run coverage.

## Rule: every bug fix comes with a test that fails on the old code

A bug fix is only done when it includes a test that **fails on the code before the fix** and passes after it. A test that was never seen failing is not evidence. It may test a copy of the logic, accept the error status, or not run at all.

How to show it:

- **Unit tests**: run the new test with the fix reverted (`git stash` the fix, or check out the parent commit), and record that it fails.
- **PHP tests**: stage only the new test, not the fix, and run `./scripts/gate-container.sh phpunit`. It tests the git index, so it runs the new test against the old code. Alternatively, make a throwaway commit that keeps the test and reverts the fix, and pass it with `--ref`.
- **Lifecycle**: `PROBE_REF=<release-without-the-fix> ./scripts/lifecycle-probe.sh` must fail, and the working state must pass.

State in the commit message or pull request what the test does on the old code. Tests that accept a 500 as "OK" or skip silently are not allowed. PHPUnit already fails on skipped tests.

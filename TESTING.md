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
| Production build (`npm run build`) | | | ✓ | |
| i18n gates (6, see below) | ✓ | ✓ | ✓ | |
| PHPStan level 5 + PHPUnit (`gate-container.sh`) | ✓ | ✓ | ✓ | |
| Secret scan (hard-coded keys and passwords) | | ✓ | | |
| SQL columns against a freshly installed schema | | ✓ | | |
| Forbidden names (trademark/publicity check) | | ✓ | | |
| Umlaut check (`ae`/`oe`/`ue` in German UI text; warning only) | | ✓ | | |
| Repo hygiene (no internal working documents in the tree) | | ✓ | | |
| Integration tests, NC 33/34/35 × PostgreSQL/MariaDB | | | | ✓ |
| Lifecycle probe, NC 33/34/35 × PostgreSQL/MariaDB | | | | ✓ |
| Persona sweep, NC 33 + PostgreSQL, gamification off | | | | ✓ |
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
(cd app && npx eslint --ext .js,.vue src/ --quiet) # ESLint, errors only
npm --prefix app run test                          # Vitest
npm --prefix app run build                         # production bundle
./scripts/check-i18n-parity.sh                     # i18n gates
./scripts/gate-container.sh all                    # PHPStan L5 + PHPUnit on the git index
./scripts/gate-container.sh all --ref HEAD         # ... or on a commit
./scripts/gate-container.sh --self-test            # proves an error and a skipped test turn the gate red
```

`gate-container.sh` runs a pinned `nextcloud:33` image (by digest) and installs PHP dependencies from the versioned `composer.lock`. PHPUnit runs with `--fail-on-skipped --fail-on-incomplete`, because a skipped test is a check that never ran. The last output line is always `GATE-RESULT phpstan=<ok|fail> phpunit=<ok|fail>`.

The interface is translated into six languages (de, en, fr, ru, ar, uk). `check-i18n-parity.sh` runs six checks:

1. Every language has the same keys.
2. `l10n/<lang>.js` matches `l10n/<lang>.json`.
3. Placeholders and script are correct for each language.
4. Every string the source code uses exists in the catalogue (source → catalogue).
5. The VirtuProf catalogue (`src/l10n/virtuprof-strings.js`) is up to date.
6. The consent texts are complete in all six languages and name the recipient.

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

- Nextcloud 33 and PostgreSQL 16, pinned by digest, with an English interface and AI off.
- Gamification is off by default. `GAMIFICATION=on` seeds it switched on and runs the same stations and detectors.
- The app comes from `git archive $REF`, so the committed bundle is what gets clicked, the same as what ships.
- A signing key is created. One mandatory-training course has two required pools of 8 questions each, certification at 75 %, Leitner off in the course, and a group of three learners enrolled.

**Roles (personas):** administrator, instructor, two learners, and the administrator in student view. Each persona opens every main tab and sub-tab of the course, the course list, the pool window, and the admin and personal settings. At each station it clicks up to 30 visible buttons. Destructive or irreversible actions are never clicked: delete, remove, revoke, unenrol, archive, reset, generate or rotate keys, start an exam. A learner passes both exams first and opens, prints and downloads the certificate.

**What each station records:**

- a screenshot
- app requests answering with 400 or above
- console errors and page errors
- an empty main area
- a success toast without a successful request before it. The toast detector checks itself at the start of each run.
- German words in the English interface

Contrast is not checked yet. The output goes to `scripts/persona-sweep/out/` (gitignored): `report.md`, `findings.json` and `shots/`.

**Fresh-eyes review (by hand).** A sweep finds what can be measured. It does not find "what is this for?" or "nobody without an IT background understands this". For that, a language model gets only the screenshots and the visible text of each station. It sees no code, no documentation and no issue list, and it answers as the person the course is built for. That person is an administrator of a small organisation with no IT background, who has to set up an annual mandatory training with certificates. For every station it answers:

1. Do I understand what this is for?
2. Does it belong here, given the settings (gamification, AI, Leitner) and the current role? With gamification off, as in a mandatory training, points, leaderboards and network simulators in a fire-safety course should not be visible. With gamification on, as in an IT course, the question is about regressions: is something missing, broken or unreadable?
3. Is it readable? This covers contrast, cut-off text, unexplained jargon, internal identifiers or placeholders, and German words in the English interface.
4. What would I report as an issue?

It also gives an overall summary: would it set up the training with this app, and what holds it up most? Its findings are hypotheses. They are checked against the code before they become a fix or an issue. The prompt template arrives with the next extension of the Persona-Gate. Until then, this review is a manual step and not part of CI.

## Gate 4: integration, lifecycle, SQL columns

All three install a real Nextcloud in throwaway containers, with no OCP stubs. Containers, volumes and networks are removed after every run. A failed cleanup fails the run.

```bash
ENGINE=pgsql ./scripts/nc-integration.sh            # app/tests/Integration on the git index
ENGINE=mysql ./scripts/nc-integration.sh --ref HEAD # ... on MariaDB, for a commit
ENGINE=pgsql ./scripts/lifecycle-probe.sh
ENGINE=mysql PROBE_REF=<git-ref> ./scripts/lifecycle-probe.sh
./scripts/schema-dump.sh /tmp/schema.txt && SCHEMA_FILE=/tmp/schema.txt python3 scripts/check-sql-columns.py
```

- **Integration** (`nc-integration.sh`) runs `app/tests/Integration` with a pinned, checksum-verified PHPUnit PHAR against Nextcloud 33. PostgreSQL 16 is the default; `ENGINE=mysql` uses MariaDB 10.11. A local mock LLM server stands in for AI providers. `INTEG_NC_IMAGE` selects another Nextcloud image; the nightly run uses 34 and 35.
- **Lifecycle** (`lifecycle-probe.sh`) runs three scenarios per database:
  - a fresh install
  - an upgrade from the 5.5.1 release with seeded legacy data
  - an upgrade from a bare 5.5.1

  Each scenario then checks the audit hash chain, a real compliance event, a translation round-trip, the AI-settings migration and the member repair step. `LIFECYCLE_NC_IMAGE` selects the Nextcloud image. This probe exists because Nextcloud runs only `changeSchema()` on a first install, never `postSchemaChange()`: data written there is missing on every new installation.
- **SQL columns** (`schema-dump.sh` + `check-sql-columns.py`) checks the column names in raw SQL against the schema of a fresh install. PHPStan cannot see a wrong column inside a string. This check runs in the pre-push hook.
- **Uninstall on both databases**: changes to raw SQL or transaction handling are also checked with `scripts/db-uninstall-probe.php`, once with `PROBE_PLATFORM=mysql` and once with `PROBE_PLATFORM=pgsql`. Instructions are in the file header. Each database has caught bugs the other could not: MariaDB treats double-quoted identifiers as strings, and PostgreSQL silently turns a `COMMIT` in a failed transaction into a rollback.

## Rule: every bug fix comes with a test that fails on the old code

A bug fix is only done when it includes a test that **fails on the code before the fix** and passes after it. A test that was never seen failing is not evidence. It may test a copy of the logic, accept the error status, or not run at all.

How to show it:

- **Unit tests**: run the new test with the fix reverted (`git stash` the fix, or check out the parent commit), and record that it fails.
- **PHP tests**: stage only the new test, not the fix, and run `./scripts/gate-container.sh phpunit`. It tests the git index, so it runs the new test against the old code. Alternatively, make a throwaway commit that keeps the test and reverts the fix, and pass it with `--ref`.
- **Lifecycle**: `PROBE_REF=<release-without-the-fix> ./scripts/lifecycle-probe.sh` must fail, and the working state must pass.

State in the commit message or pull request what the test does on the old code. Tests that accept a 500 as "OK" or skip silently are not allowed. PHPUnit already fails on skipped tests.

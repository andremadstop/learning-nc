# Contributing

Thanks for helping. Learning is maintained by one person. The most useful contributions are clear bug reports, corrected translations and small, focused pull requests.

Security problems do not belong in a public issue. See [SECURITY.md](SECURITY.md).

## Reporting a bug

Open an issue with the **Bug report** form. A report can be fixed quickly when it includes:

- **App version**: Apps → Learning, or the version in the App Store.
- **Nextcloud version and database**: Administration settings → Overview; PostgreSQL, MySQL/MariaDB or SQLite.
- **Your role** when it happened: Nextcloud administrator, instructor, learner, or "student view". Many tabs and buttons depend on the role.
- **Gamification and AI on or off**: Administration settings → Learning. Whether Leitner, training or exam mode is switched on in the course matters too. Much of the interface changes with these settings.
- **Steps to reproduce**, starting from a page you can name, then what you **expected** and what **actually happened**.
- **Screenshots**. Remove names and e-mail addresses of real people first.
- The interface language, if text looks wrong or untranslated.
- Error messages from the browser console or the Nextcloud log, if you have them.

One problem per issue, please. Ten small issues get fixed faster than one long list.

## Suggesting a feature

Use the **Feature request** form, and describe the **problem** before the solution. "Instructors cannot see who has not started the course yet" leads to a better result than "add a column X to table Y". The proposed solution may solve a different problem than the one you have. Your use case (who, how many learners, what the course is for) usually decides how something gets built.

## Translations

The interface ships in six languages: German (the source), English, French, Russian, Arabic and Ukrainian. None of them has been reviewed by a native speaker. Corrections are very welcome and need no code; see [the manual](docs/MANUAL.md#7-languages-and-translations).

## Development setup

You need:

- **Node.js 22** (CI uses 22.23.4) and npm
- **Docker**: every PHP check runs in a throwaway Nextcloud container, so **no local PHP is needed**
- **Python 3** (standard library only) for the i18n checks
- git and bash

```bash
git clone https://github.com/andremadstop/learning-nc.git
cd learning-nc
git config core.hooksPath .githooks   # pre-commit and pre-push run the quality gates
npm --prefix app ci
npm --prefix app run build
```

The Nextcloud app is the `app/` directory. To try a change in a running Nextcloud, copy `app/` to `custom_apps/learning` and enable it with `occ app:enable learning`. `docker-compose.e2e.yml` starts a local Nextcloud for that; see [`app/tests/e2e/README.md`](app/tests/e2e/README.md).

Both hooks need Docker, including for commits that only change documentation: pre-commit runs PHPStan and PHPUnit in a container. A full pre-commit run takes a few minutes. [TESTING.md](TESTING.md) explains each gate and how to run it on its own.

## Conventions

- **Commits**: [Conventional Commits](https://www.conventionalcommits.org/) in English, for example `fix(exam): …`, `feat(certificates): …`, `test(persona): …`, `docs(readme): …`. Add the issue number when there is one: `fix(exam): … (#47)`.
- **Bug fixes come with a test that fails on the old code** (see [TESTING.md](TESTING.md#rule-every-bug-fix-comes-with-a-test-that-fails-on-the-old-code)). New endpoints and utilities need at least one test.
- **Frontend**: Vue 3 with the **Options API** for every component (no `<script setup>`), Pinia, `@nextcloud/vue` 9. ESLint must report 0 errors; warnings are allowed.
- **Built bundle**: `app/js/` is committed, because it is what the App Store tarball ships. After changing anything under `app/src/` (including `app/src/l10n/`), run `npm run build` and commit the result together with the source change.
- **Backend**: PHP 8.2+, Nextcloud App Framework, `QBMapper` for database access, dependency injection via `Application.php`. PHPStan level 5 must pass.
- **Database**: support PostgreSQL and MySQL/MariaDB. Changes to raw SQL or transactions are tested on both (see Gate 4 in TESTING.md). Tables belong in `changeSchema()`. Data seeds belong in a repair step registered for both `<install>` and `<post-migration>`, because a fresh install never runs `postSchemaChange()`.
- **Strings**: source strings are written in **German** and wrapped in `t('learning', '…')`. Every new string needs an entry in all six `app/l10n/<lang>.json` files. The `.json` file is the source; regenerate the matching `.js` files with `python3 scripts/l10n_js_sync.py` instead of editing them by hand. `./scripts/check-i18n-parity.sh` must pass.
- **No secrets** in code, tests or commits.

## Pull requests

Pull requests are welcome. The repository on GitHub is a mirror of the maintainer's main repository: an accepted pull request is applied there and appears here with the next sync, with your authorship kept. Please:

- keep a pull request to one topic and reference the issue
- make sure CI is green (it runs the same Gate 1 as the hooks)
- state in the description how you tested it and, for a bug fix, what the new test does on the old code

## Releases

Releases are made by the maintainer, not by CI. Signing keys and the App Store token never leave the maintainer's machine. In outline:

1. The version in `app/appinfo/info.xml`, the top entry of `app/CHANGELOG.md` and the git tag must match.
2. All gates pass, including Gate 4 on both databases.
3. The release tarball is built from the tagged commit. Its file list is compared with the previous release: nothing may be missing without a reason.
4. The tarball is signed in a throwaway Nextcloud (`occ integrity:sign-app`), and `signature.json` is committed.
5. Boot gate: the signed tarball is installed in a clean Nextcloud and upgraded from the previous release, the integrity check must come back empty, and a course page must load. This catches corrupt or empty files that a file-list comparison does not see.
6. The release is published and uploaded to the Nextcloud App Store, then `scripts/verify-release.sh <version>` checks what the store actually delivers: a reachable download URL, matching signature and version, and the expected files.

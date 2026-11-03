# Acknowledgements

Learning is about 61,000 lines of PHP and 75,000 lines of Vue and JavaScript, and still most of what makes it work was built by others: the platform it runs in, the frameworks, the scheduling research, the signature scheme, the test tools. This is who they are, what the app uses their work for, and under which licence it is passed on. Licences are read from the installed packages, not from memory.

## The platform

| Component | Used for | Licence |
|---|---|---|
| [Nextcloud](https://nextcloud.com/) server 33 to 35 (`nextcloud/ocp`) | the app framework: users, groups, files, settings, background jobs, database access (QBMapper), the App Store | AGPL-3.0 |
| [PHP](https://www.php.net/) 8.1+ with `ext-sodium` | backend; `sodium_crypto_sign_*` for Ed25519 certificate signatures | PHP License / ISC (libsodium) |
| [PostgreSQL](https://www.postgresql.org/) 13+ or [MySQL](https://www.mysql.com/) 8+ | storage | PostgreSQL License / GPL-2.0 |
| [Redis](https://redis.io/) (optional) | leaderboards and session data | see Redis licensing |

## In the interface

| Package | Used for | Licence |
|---|---|---|
| [Vue](https://vuejs.org/) 3.5 with [vue-router](https://router.vuejs.org/) and [Pinia](https://pinia.vuejs.org/) | the single-page app and its state | MIT |
| [@nextcloud/vue](https://github.com/nextcloud-libraries/nextcloud-vue) 9 | Nextcloud's own components, so the app looks like the rest of the server | AGPL-3.0-or-later |
| [@nextcloud/dialogs](https://github.com/nextcloud-libraries/nextcloud-dialogs) | dialogs and toasts | AGPL-3.0-or-later |
| [@nextcloud/axios](https://github.com/nextcloud-libraries/nextcloud-axios), [@nextcloud/router](https://github.com/nextcloud-libraries/nextcloud-router), [@nextcloud/l10n](https://github.com/nextcloud-libraries/nextcloud-l10n) | requests, routes and translations the Nextcloud way | GPL-3.0-or-later |
| [D3](https://d3js.org/) (`d3-force`, `d3-selection`, `d3-shape`, `d3-transition`, `d3-zoom`) | the skill map and the quest map of the story campaigns | ISC |
| [canvas-confetti](https://github.com/catdad/canvas-confetti) | the moment a learner earns a badge or finishes a session | ISC |
| [linkifyjs](https://linkify.js.org/) | declared in `package.json`, not imported by the current source | MIT |

## The science and the standards

- **Spaced repetition** goes back to Hermann Ebbinghaus's memory experiments and Sebastian Leitner's card boxes; the five boxes in this app are Leitner's. See the colophon below for the sources.
- **FSRS-5** is the Free Spaced Repetition Scheduler by Jarrett Ye and the [open-spaced-repetition](https://github.com/open-spaced-repetition) project. `app/lib/Service/FsrsService.php` is a PHP implementation of the published algorithm with its default weights; the learner's "memory strength" ordering in the smart queue comes from it.
- **Certificates** are signed with **Ed25519** (Bernstein, Duif, Lange, Schwabe and Yang, 2011) through libsodium, and issued as [Open Badges 3.0](https://www.imsglobal.org/spec/ob/v3p0) / [W3C Verifiable Credentials](https://www.w3.org/TR/vc-data-model-2.0/) with a `did:web` issuer, so other software can verify them without asking this server.
- **Story campaigns** retell real incidents (WannaCry, SolarWinds, Log4Shell) in the app's own words from public reporting; no vendor material is included.

## Checked with

| Tool | Used for | Licence |
|---|---|---|
| [Vitest](https://vitest.dev/) with [happy-dom](https://github.com/capricorn86/happy-dom) | the JavaScript and component tests (`npm test`) | MIT |
| [Vite](https://vitejs.dev/) with `@vitejs/plugin-vue` | building `js/learning.js` | MIT |
| [ESLint](https://eslint.org/) with `eslint-plugin-vue` and `typescript-eslint` | style and typical mistakes in the frontend | MIT |
| [PHPStan](https://phpstan.org/) level 5 | static analysis of the backend | MIT |
| [PHPUnit](https://phpunit.de/) 10 | backend tests | BSD-3-Clause |
| [Playwright](https://playwright.dev/) | end-to-end smoke tests against a running Nextcloud (`docker-compose.e2e.yml`) | Apache-2.0 |
| [ShellCheck](https://www.shellcheck.net/) | the hook scripts themselves | GPL-3.0 |
| `.githooks/pre-push` with `scripts/check-*.py` and `check-*.sh` | the gates: i18n parity and placeholders, SQL column names, `v-html` safety, forbidden names, repo hygiene; a commit that fails a gate is not pushed | this repository |
| [Git](https://git-scm.com/) | versions | GPL-2.0 |
| Claude (Anthropic), Codex (OpenAI), Gemini (Google) | help writing code, reviews and text | not open, named here for that reason |

On the last line: I build with AI and say so. What goes into the repository runs on my own Nextcloud with real course groups first, passes the pre-push gates (PHPStan, ESLint, Vitest, PHPUnit in a throwaway container) and the App Store's own checks, and every release is listed in `app/CHANGELOG.md` with what changed and what was fixed. The legal review for the mentor archetypes, which decided against naming real scientists, is in `.planning/LEGAL.md`.

## Translations

The interface ships in German (source), English, French, Russian, Arabic and Ukrainian. The translations were produced with machine assistance and have not been reviewed by native speakers; corrections are welcome and need no code (see the manual).

## Colophon

The idea behind every box in this app is older than the computer. Hermann Ebbinghaus, then a Privatdozent at the Berlin university, published *Über das Gedächtnis* in 1885 (Leipzig: Duncker & Humblot): memory experiments on himself with nonsense syllables, in two periods, 1879/80 and 1883/84. Chapter VII, "retention and forgetting as a function of time", holds the numbers that were later drawn as the forgetting curve. Sebastian Leitner put the consequence into a cardboard box in 1972 in *So lernt man lernen* (Freiburg: Herder): five compartments, a card you know moves on, a card you miss goes back to the first. Piotr Woźniak wrote the first SuperMemo in December 1987; its algorithm, known as SM-2 and described in his 1990 thesis *Optimization of learning*, is behind most spaced-repetition software since. Jarrett Ye's FSRS (open-spaced-repetition, since 2022; FSRS-5 in 2024, the version implemented here) replaced the fixed intervals with a model fitted to review histories; Anki ships it since version 23.10.

The certificates rest on two more pieces of other people's work. Ed25519 was presented by Daniel J. Bernstein, Niels Duif, Tanja Lange, Peter Schwabe and Bo-Yin Yang at CHES 2011 in *High-speed high-security signatures*: 32-byte keys, 64-byte signatures, which is why a certificate here fits into a URL and can be checked without asking this server. And Nextcloud itself began on 2 June 2016, when Frank Karlitschek forked ownCloud and founded Nextcloud GmbH; the app lives entirely inside what that project provides.

Built in Potsdam, half an hour from the university where Ebbinghaus was teaching when his book came out.

Sources: H. Ebbinghaus, *Über das Gedächtnis* (1885), [scan of the first edition](https://archive.org/download/b28111710/b28111710_djvu.txt) and [English translation (Ruger/Bussenius 1913, York University)](https://www.yorku.ca/pclassic/Ebbinghaus/memory3.htm) · S. Leitner, *So lernt man lernen* (1972), [library record (hbz)](http://lobid.org/resources/990006043230206441) and [Universität Göttingen on the five compartments](https://www.uni-goettingen.de/de/578134.html) · P. A. Woźniak, [SM-2 and the 1990 thesis](https://www-v1.supermemo.com/archives1990-2015/english/ol/sm2), [Anki on its algorithm](https://docs.ankiweb.net/faqs/what-spaced-repetition-algorithm) · [fsrs4anki](https://github.com/open-spaced-repetition/fsrs4anki) (MIT, 2022), [fsrs-rs 1.0.0 with FSRS-5](https://github.com/open-spaced-repetition/fsrs-rs/releases/tag/v1.0.0) (BSD-3-Clause, July 2024), [Anki 23.10 release notes](https://github.com/ankitects/anki/releases/tag/23.10); J. Ye, J. Su, Y. Cao, *A Stochastic Shortest Path Algorithm for Optimizing Spaced Repetition Scheduling*, KDD 2022 · D. J. Bernstein et al., [High-speed high-security signatures](https://ed25519.cr.yp.to/papers.html), CHES 2011, Journal of Cryptographic Engineering 2 (2012) · F. Karlitschek, [Nextcloud announcement, 2 June 2016](https://karlitschek.de/2016/06/nextcloud/), [nextcloud/server](https://github.com/nextcloud/server) (AGPL-3.0).

André Stiebitz, Potsdam, November 2026

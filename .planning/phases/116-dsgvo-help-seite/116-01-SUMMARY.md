---
phase: 116-dsgvo-help-seite
plan: "01"
subsystem: ui
tags: [nextcloud, settings, dsgvo, privacy, php, vue]

requires:
  - phase: 116-dsgvo-help-seite
    provides: ISettings classes, privacy route, legal-link template, Application.php registration

provides:
  - PrivacyHelpSettings.php with correct 'additional' section ID
  - LegalNoticeHelpSettings.php with correct 'additional' section ID
  - templates/settings/legal-link.php deployed to container
  - deploy-dev.sh includes templates/ in PHP bundle
  - build/learning/ release artifacts updated with all 3 missing files

affects: [release, dsgvo-compliance, settings-panel]

tech-stack:
  added: []
  patterns:
    - "NC ISettings implementation: getSection() must return a registered section ID ('additional', not custom)"
    - "deploy-dev.sh PHP bundle: tar must include templates/ alongside lib/ appinfo/ l10n/"

key-files:
  created:
    - build/learning/templates/settings/legal-link.php
  modified:
    - app/lib/Settings/PrivacyHelpSettings.php
    - app/lib/Settings/LegalNoticeHelpSettings.php
    - scripts/deploy-dev.sh
    - build/learning/lib/Settings/PrivacyHelpSettings.php
    - build/learning/lib/Settings/LegalNoticeHelpSettings.php

key-decisions:
  - "DSGVO: Settings section 'tips-tricks' replaced with 'additional' — NC core registers 'additional', 'tips-tricks' was unregistered and caused silent drop"
  - "DSGVO: deploy-dev.sh PHP bundle extended to include templates/ — legal-link.php now reliably reaches container"

patterns-established:
  - "ISettings.getSection() rule: always verify target section is registered by NC core or app before using"
  - "deploy-dev.sh PHP bundle: templates/ must be in both rsync and tar steps"

requirements-completed:
  - DSGVO-01
  - DSGVO-02
  - DSGVO-03

duration: ~15min
completed: 2026-03-30
---

# Phase 116 Plan 01: DSGVO Help-Seite Summary

**Fixed NC settings section ID ('tips-tricks' → 'additional') and extended deploy script to bundle templates/, making DSGVO Privacy Policy and Impressum links appear in /settings/help and /settings/personal**

## Performance

- **Duration:** ~15 min
- **Started:** 2026-03-30
- **Completed:** 2026-03-30
- **Tasks:** 3 (2 auto + 1 human-verify)
- **Files modified:** 5

## Accomplishments

- Fixed silent drop of DSGVO settings links — `getSection()` now returns `'additional'` (registered by NC core) instead of `'tips-tricks'` (unregistered, caused silent drop)
- Extended `deploy-dev.sh` PHP tar bundle to include `templates/` — `legal-link.php` now reliably reaches the Docker container on deploy
- Updated all 3 previously missing `build/learning/` release artifacts (PrivacyHelpSettings.php, LegalNoticeHelpSettings.php, templates/settings/legal-link.php)
- User browser-verified all 3 DSGVO requirements: /settings/help Privacy link, /apps/learning/privacy 7 categories, /settings/personal Additional section links

## Task Commits

1. **Task 1: Fix section ID + deploy script templates gap** - `c3244b1` (fix)
2. **Task 2: Deploy to container + update build output** - `3ba7a84` (chore)
3. **Task 3: Verify DSGVO links in browser** - human-verify, user approved

## Files Created/Modified

- `app/lib/Settings/PrivacyHelpSettings.php` — `getSection()` changed from `'tips-tricks'` to `'additional'`
- `app/lib/Settings/LegalNoticeHelpSettings.php` — same fix
- `scripts/deploy-dev.sh` — added `rsync -az app/templates/` step + `templates/` in tar command
- `build/learning/lib/Settings/PrivacyHelpSettings.php` — release artifact updated
- `build/learning/lib/Settings/LegalNoticeHelpSettings.php` — release artifact updated
- `build/learning/templates/settings/legal-link.php` — release artifact created (was missing)

## Decisions Made

- Used NC core's `'additional'` section for both Settings forms — no new section registration needed, zero extra code
- `templates/` added to both the rsync and tar steps of `deploy-dev.sh` — ensures consistency between VM and container state

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

None — root causes were precisely diagnosed in the plan. Two targeted edits fixed both blockers.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- DSGVO compliance (DSGVO-01, DSGVO-02, DSGVO-03) fully verified in browser
- All release artifacts up to date in build/learning/
- Phase 116 complete — next: Phase 117 (Dashboard Prüfungstermin) or as directed

---
*Phase: 116-dsgvo-help-seite*
*Completed: 2026-03-30*

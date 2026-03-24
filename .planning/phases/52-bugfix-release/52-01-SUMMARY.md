---
phase: 52-bugfix-release
plan: 01
subsystem: infra
tags: [deploy, app-store, signing, bugfix, nextcloud]

requires: []
provides:
  - "Fixed Binary Tab deployed to learning-dev"
  - "Signed v3.0.0 release uploaded to Nextcloud App Store"
affects: []

tech-stack:
  added: []
  patterns: []

key-files:
  created: []
  modified:
    - app/js/learning.js
    - app/appinfo/signature.json

key-decisions:
  - "Updated existing v3.0.0 GitHub release asset instead of creating new tag"

patterns-established: []

requirements-completed: [FIX-01, FIX-02]

duration: 4min
completed: 2026-03-24
---

# Phase 52 Plan 01: Bugfix & Release Summary

**Binary Tab calculateSubnet fix deployed to learning-dev, v3.0.0 re-signed and uploaded to Nextcloud App Store**

## Performance

- **Duration:** 4 min
- **Started:** 2026-03-24T06:52:26Z
- **Completed:** 2026-03-24T06:56:17Z
- **Tasks:** 2
- **Files modified:** 2

## Accomplishments
- Deployed fixed JS bundle with calculateSubnet ip field to learning-dev Docker container
- Re-signed app with integrity certificate and rebuilt tarball with signature.json
- Replaced GitHub release tarball and uploaded signed release to App Store (HTTP 200)
- Cleaned up signing keys from learning-dev and container immediately after use

## Task Commits

Each task was committed atomically:

1. **Task 1: Deploy Binary Tab Fix to learning-dev** - `a02e92e` (fix)
2. **Task 2: Build, Sign, and Upload Release to App Store** - `11f282d` (chore)

## Files Created/Modified
- `app/js/learning.js` - Fixed JS bundle with calculateSubnet returning ip field for Binary Tab
- `app/appinfo/signature.json` - Updated app signature for App Store validation

## Decisions Made
- Updated existing v3.0.0 GitHub release asset rather than creating a new tag, since v3.0.0 already existed

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Fixed container file ownership for signing**
- **Found during:** Task 2 (App signing)
- **Issue:** `/var/www/html/custom_apps/learning/appinfo` was not writable by www-data, causing occ integrity:sign-app to fail
- **Fix:** Ran `chown -R www-data:www-data` on the app directory in the container before signing
- **Files modified:** None (runtime fix)
- **Verification:** Signing succeeded after ownership fix
- **Committed in:** 11f282d (Task 2 commit)

---

**Total deviations:** 1 auto-fixed (1 blocking)
**Impact on plan:** Auto-fix necessary for signing to work. No scope creep.

## Issues Encountered
- File permission conflicts on learning-dev /tmp directory required `sudo rm` before scp could write — resolved by cleaning up stale files first
- App Store API version check returned parse error (possibly format change) but upload itself returned HTTP 200

## User Setup Required

None - App Store token was already present in .env.

## Next Phase Readiness
- Binary Tab fix is live on learning-dev
- v3.0.0 is the current App Store release
- Ready for Phase 53 (Content rollout)

## Self-Check: PASSED

All files exist, all commits verified.

---
*Phase: 52-bugfix-release*
*Completed: 2026-03-24*

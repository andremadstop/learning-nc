---
phase: 151-skin-picker-framework
plan: 04
status: completed
completed: 2026-04-25
requirements: [PICK-02]
---

# Plan 151-04 Summary — VirtuProf Skin Persistence

## Outcome
`VirtuProfController` now reads and writes the bare NC user_config key `virtuprof_skin`, exposes `skin` in `/api/virtuprof/state`, and normalizes invalid values back to `nova`. `scripts/test-api.sh` now contains the prepared PUT/GET round-trip assertion and resets the skin to Nova afterward.

## Files Modified
| Path | Change | Lines |
|---|---|---:|
| `app/lib/Controller/VirtuProfController.php` | Added skin allowlist, getter/normalizer, state payload field, and nullable `savePreferences()` param | 829 |
| `scripts/test-api.sh` | Added VirtuProf skin round-trip assertions and reset | 822 |

## Verification
- ✅ `ssh relais 'docker exec -w /var/www/html/custom_apps/learning devcloud-app php vendor/bin/phpstan analyse --no-progress lib/Controller/VirtuProfController.php'` reports `[OK] No errors`
- ✅ `bash -n scripts/test-api.sh` exits 0
- ✅ Grep confirms `virtuprof_skin`, `prof_lern_classic`, and skin round-trip assertions

## Deviations from PLAN
Local `php -l` could not run because PHP is not installed on the workstation. PHPStan was run through the configured relay/container path.

## Open Risks / Follow-ups
The live API round-trip block is prepared but not executed in this plan; it runs after the next backend deploy/Gate-2 invocation.

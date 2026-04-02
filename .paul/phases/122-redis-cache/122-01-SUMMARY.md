# Phase 122 Summary — Redis Cache Normalization

## Outcome
- Normalized `UserStateController` cache access behind a single `user_state_*` key helper.
- Renamed `LernprofilService` cache keys to `profile_{userId}_{courseId|all}` and added legacy `lernprofil_*` invalidation for backward compatibility.
- Added cache invalidation to `XpService` so recalculated XP/level updates clear both `user_state_*` and `profile_*` caches directly instead of relying only on callers.
- Audited other `ICacheFactory` users: no `createLocal()` usage remained; `StoryEngineService` already used `createDistributed('learning')` and required no code change.

## Verification
- `rg -n 'createLocal|new XpService' app/lib app/tests` → 0 matches
- `git diff --check` → clean
- `./scripts/deploy-dev.sh --php-only` → PHP deploy + PHPStan clean on `learning-dev`
- `npm run test -- --run` → 748 passed

## Notes
- The app still has no Redis dependency of its own; it only uses Nextcloud's `ICacheFactory::createDistributed('learning')`.
- Course-specific `profile_*` cache keys still rely on TTL expiration; this phase only guarantees immediate invalidation for the global profile aggregate.

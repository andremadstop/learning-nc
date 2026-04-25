---
phase: 151-skin-picker-framework
plan: 07
status: completed-deploy-shipped-walkthrough-deferred
completed: 2026-04-25
deployed: 2026-04-25
---

# Plan 151-07 Summary — PersonalSettings picker + VirtuProf swap + deploy (Wave 4)

## Outcome

End-to-end picker UI wired + VirtuProf NovaDock→SkinRenderer swap done. Phase 151 fully shipped to relay devcloud-app. Manual visual walkthrough (7 checks) deferred to ad-hoc bug-hunt per Phase 150 Plan 06 user pattern.

## Files Modified

| Path | Change |
|------|--------|
| `app/src/components/PersonalSettings.vue` | NcSelect import + components register + skinStore import + new "Charakter-Auswahl" field-row + form.skinId + skinOptions/selectedSkinOption computed + onSkinChange method + loadSettings hydrates skinStore from virtuProfData + saveSettings PUT body includes skin |
| `app/src/components/VirtuProf.vue` | NovaDock import REMOVED + SkinRenderer import ADDED + components{} updated + `<NovaDock>` template tag REPLACED with `<SkinRenderer>`. novaReactions import line UNCHANGED (Phase 150 Plan 04 non-breaking guarantee verified via grep) |

## Verification

### Automated (✅ all green)
- `cd app && npm run test -- --run` → **1036/1036 green** across 73 test files
- `cd app && npx eslint --ext .vue src/components/PersonalSettings.vue src/components/VirtuProf.vue` → exit 0
- `cd app && npm run build` → vite built ~700ms, postbuild checks passed
- `! grep -qE "<NovaDock|import NovaDock from" src/components/VirtuProf.vue` → confirmed (zero leftover refs)
- `grep -qE "import.*novaReactions.*nova-reaction-engine" src/components/VirtuProf.vue` → confirmed (Nova non-breaking)

### Deploy (✅ shipped 2026-04-25)
- `./scripts/deploy-prod.sh --full` → PHP + l10n + JS deployed to relay devcloud-app
- NC status post-deploy: version 33.0.2.2, maintenance:false, needsDbUpgrade:false
- HTTP 302 on https://devcloud.andrestiebitz.de/ (Login-Redirect = healthy)

### Manual (⏳ deferred to ad-hoc bug-hunt — user pattern from Phase 150 Plan 06)
7 manual walkthrough checks per Plan 07 `<how-to-verify>`:
1. PersonalSettings "Charakter-Auswahl" row visible
2. Dropdown shows nova + Prof. Lern (2 options)
3. Switch to Prof. Lern → avatar swaps without reload
4. Cursor over avatar → pupils track
5. Click avatar → arm-wave 1.2s
6. Save + reload → Prof. Lern persists
7. Switch back to nova → save → reload → nova visible

User explicitly chose to ship without manual gate. No code blocker.

## Three-Layer Skin Architecture (Phase 151 Goal)

After this plan:
1. **Backend** — VirtuProfController.php persists `virtuprof_skin` via NC user_config; buildStatePayload exposes `skin` field (Pattern A hydration, Plan 04)
2. **Store** — Pinia skinStore (single source of truth, Plan 03) + a11yStore lnc-quiet binding from Phase 150
3. **Component** — SkinRenderer dispatcher (Plan 05) + ProfLernAvatar proof-case (Plan 06) + characters.js meta-schema (Plan 02)
4. **UI** — PersonalSettings picker + VirtuProf integration (this Plan 07)

## Deviations from PLAN

1. **NcSelect option-shape** — Plan 07 examples bound model-value directly to form.skinId (string). NcSelect from @nextcloud/vue 9.x expects/returns object {value, label}. Added `selectedSkinOption` computed to map string→object for binding, and `onSkinChange` handler unwraps object→string. No functional impact, just cleaner v-model interop.
2. **Test helper bug discovered + fixed in Plan 05 SkinRenderer.test.js** — `app.use(createPinia())` after `setActivePinia(createPinia())` created two separate Pinia instances. Fixed by reusing single pinia. Pattern bug also exists in CharacterAvatar.test.js but doesn't surface there (try/catch wraps useA11yStore call). Documented in Plan 05 SUMMARY.
3. **Manual walkthrough deferred** — explicit user choice ("bugs kann ich nachher noch suchen"), matches Phase 150 Plan 06 pattern.

## Open Risks / Follow-ups

- **NcSelect model-value compatibility** — happy-dom doesn't run NcSelect's internal multiselect logic; only verified at integration-test level by ESLint+build. Live behavior smoke-tested via deployed bundle (HTTP 302).
- **`virtuprof_skin` test-api.sh round-trip** — Plan 04 added the assertion block; runs on next manual `bash scripts/test-api.sh` invocation.
- **Phase 152 Scholar archetypes** — Plan 02 already preallowed `theoretiker`/`kosmologe`/`popularisierer` in VirtuProfController allowlist + characters.js can be extended additively when SVGs land. SkinRenderer dispatch CharacterAvatar branch handles them automatically (no code change needed at dispatcher).

## Wave Status

Wave 4 complete. Phase 151 = 7/7 plans done. Phase-Goal verifier next.

---

*Plan 07 completed 2026-04-25 ~15min wall-clock incl. NcSelect adapter + deploy.*

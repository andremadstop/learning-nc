---
phase: 151-skin-picker-framework
verified: 2026-04-25T08:10:00Z
status: passed
score: 12/12 requirements satisfied (3 with deferred manual visual confirmation per user choice)
re_verification: false
---

# Phase 151: Skin Picker Framework & Prof. Lern Classic — Verification Report

**Phase Goal:** Users can select their VirtuProf skin in PersonalSettings and the choice persists in NC user_config; the dispatcher picks the right avatar at runtime; Prof. Lern Classic is restored from git tag v2.6.1 (style-anchor only — actual source HEAD VirtuProfAvatar.vue per Reality Check #1) and migrated to Vue 3 as the simplest proof-case of the picker framework.

**Verified:** 2026-04-25
**Status:** passed
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths (derived from ROADMAP Success Criteria)

| #   | Truth | Status | Evidence |
| --- | ----- | ------ | -------- |
| 1 | PersonalSettings has dropdown listing user_selectable skins (Nova + Prof. Lern); selecting saves to NC user_config `learning.virtuprof_skin` via VirtuProfController | VERIFIED | Picker row at `PersonalSettings.vue:107` (`Charakter-Auswahl` label), `useSkinStore` import at line 399, `onSkinChange` at line 628, save body at line 657. Backend `VirtuProfController.php:386` writes `virtuprof_skin`. Deployed bundle contains `Charakter-Auswahl` (1× in learning-personal-settings.js) and `prof_lern_classic` references. |
| 2 | VirtuProf.vue swaps `<NovaDock>` for `<SkinRenderer>`; invalid skinId falls back to Nova | VERIFIED | `<SkinRenderer` at `VirtuProf.vue:28`; `import NovaDock` removed; `import SkinRenderer` at line 92. SkinRenderer dispatches based on `skinId` (lines 47-52). Fallback chain: `skinStore.setSkin()` coerces unknown id to `nova` (skinStore.js:24-29), then SkinRenderer routes `'nova'` → NovaDock. |
| 3 | Mid-session skin swap reactive without page reload via `:key="skinId"` remount + Pinia single source of truth | VERIFIED | `:key="skinId"` at `SkinRenderer.vue:4`; `skinId` computed reads `useSkinStore().skinId` (line 44-46). PICK-04 vitest test ("PICK-04 :key remount — changing skinId from nova to prof_lern_classic swaps the rendered child") passes in the 1036/1036 suite. |
| 4 | ProfLernAvatar.vue ships with book + gaze + wave + question mark + reduced-motion gating | VERIFIED | `data-prof-feature="book"` at `ProfLernAvatar.vue:29`; `data-prof-feature="pupils"` at line 54; `mousemove` listener at line 147; `playWave` import line 110, call line 199; `is-waving` class on wrapper line 5; `role="img"` on SVG at line 14; `prefersReducedMotion` gate present. ProfLernAvatar.test.js (7/7) green. |
| 5 | characters.js extended additively with `user_selectable` + `category` + `preview_thumbnail_svg`; existing 12 entries unchanged | VERIFIED | All 14 entries have the 3 fields (grep counts 14× each). nova: user_selectable:true, category:'hero'. prof_lern_classic: user_selectable:true, category:'classic'. Other 12: user_selectable:false, category:'campaign'. characters.test.js (6/6) and CharacterAvatar.test.js (10/10) green — no regression. |

**Score:** 5/5 truths verified.

### Required Artifacts

| Artifact | Expected | Status | Details |
| -------- | -------- | ------ | ------- |
| `app/tests/unit/skinStore.test.js` | TDD-init failing tests (Wave 0) | VERIFIED | Exists; imported via skinStore.js, runs as part of 1036/1036 green suite |
| `app/tests/unit/SkinRenderer.test.js` | TDD-init failing tests | VERIFIED | Exists; 5 dispatch tests now GREEN |
| `app/tests/unit/ProfLernAvatar.test.js` | TDD-init failing tests | VERIFIED | Exists; 7 augmentation tests now GREEN |
| `app/tests/unit/characters.test.js` | TDD-init failing tests | VERIFIED | Exists; 6 META/CLASSIC schema tests now GREEN |
| `app/src/data/characters.js` | 3 new fields on all entries + prof_lern_classic entry | VERIFIED (290 lines) | 14× `user_selectable`, `category`, `preview_thumbnail_svg`. nova=hero, prof_lern_classic=classic, rest=campaign |
| `app/src/stores/skinStore.js` | useSkinStore Pinia store, allowlist coercion, availableSkins getter | VERIFIED (38 lines) | `defineStore('skin')`, `DEFAULT_SKIN='nova'`, setSkin allowlist, loadFromServerPayload, availableSkins getter all present |
| `app/lib/Controller/VirtuProfController.php` | virtuprof_skin field in buildStatePayload + savePreferences param + allowlist | VERIFIED (829 lines) | `getSkin()` line 181-185, `normalizeSkin()` line 197-199 with `ALLOWED_SKINS` const (line 23-29: nova, prof_lern_classic, theoretiker, kosmologe, popularisierer), `?string $skin = null` param at line 351, persistence at line 382-389. PHPStan Level 5 clean per Plan 04 SUMMARY |
| `scripts/test-api.sh` | Round-trip assertion block | VERIFIED | Line 691: PUT skin:prof_lern_classic; line 693+697: round-trip assertions; reset to nova present |
| `app/src/components/ProfLernAvatar.vue` | Restored chibi avatar with book + gaze + wave + reduced-motion + a11y | VERIFIED (392 lines) | All required features present (see Truth 4 evidence). Component name 'ProfLernAvatar' |
| `app/src/components/VirtuProfAvatar.vue` | REMOVED (no shim, zero consumers) | VERIFIED | File does not exist. `git mv` per Plan 06; no callers found |
| `app/src/components/SkinRenderer.vue` | Polymorphic dispatcher with `:key` remount | VERIFIED (73 lines) | Static imports of NovaDock + ProfLernAvatar + CharacterAvatar; `<component :is="rendererComponent" :key="skinId">`; computed dispatch + forwardedProps prop-shape adapter for CharacterAvatar |
| `app/src/components/PersonalSettings.vue` | Picker row + skinStore hydration + saveSettings PUT body | VERIFIED (917 lines) | `id="virtuprof-skin"` line 110, `Charakter-Auswahl` line 107, `useSkinStore()` lines 399/468/554/633, hydration `loadFromServerPayload(virtuProfData)` line 554, save body `skin: this.form.skinId` line 657 |
| `app/src/components/VirtuProf.vue` | NovaDock → SkinRenderer swap; novaReactions import preserved byte-identical | VERIFIED (2363 lines) | `<SkinRenderer` line 28; `import SkinRenderer` line 92; `import NovaDock` removed; `components: { SkinRenderer, ... }` line 219; `import { novaReactions } from '../utils/nova-reaction-engine.js'` byte-identical at line 96 (verified via `git diff 983c3f1^ 983c3f1` returns empty for novaReactions matches) |

### Key Link Verification

| From | To | Via | Status | Details |
| ---- | -- | --- | ------ | ------- |
| skinStore.js | characters.js | `import { CHARACTERS }` for allowlist + availableSkins filter | WIRED | `import { CHARACTERS } from '../data/characters.js'` line 3; used in `setSkin` allowlist (line 25) + `availableSkins` getter (line 21) |
| SkinRenderer.vue | skinStore.js | `useSkinStore()` in computed | WIRED | Import line 13; usage line 45 (skinId computed) |
| SkinRenderer.vue | NovaDock.vue | import + render branch nova | WIRED | Import line 10; branch line 48 |
| SkinRenderer.vue | ProfLernAvatar.vue | import + render branch prof_lern_classic | WIRED | Import line 11; branch line 49 |
| SkinRenderer.vue | CharacterAvatar.vue | import + render fallback for valid char id | WIRED | Import line 12; branch line 51 |
| ProfLernAvatar.vue | character-animations.js | `playWave()` for click arm-wave | WIRED | Import line 110; call line 199 inside `onAvatarClick` (Phase 150 a11y-gated primitive) |
| ProfLernAvatar.vue | DOM mousemove | wrapper.addEventListener with matchMedia gate | WIRED | Listener attached line 147; cleaned up line 151 in beforeUnmount; `prefersReducedMotion` gate present |
| VirtuProfController.php | NC user_config | setUserValue('learning', 'virtuprof_skin', value) + getUserValue | WIRED | Read line 183 (default 'nova'); write line 386 (after `normalizeSkin` allowlist) |
| PersonalSettings.vue | skinStore.js | useSkinStore in computed + load + onSkinChange | WIRED | 4 usages: skinOptions computed (468), selectedSkinOption (471), loadFromServerPayload (554), setSkin in onSkinChange (633) |
| PersonalSettings.vue | VirtuProf API | PUT body includes skin field | WIRED | `skin: this.form.skinId` in saveSettings PUT body line 657 |
| VirtuProf.vue | SkinRenderer.vue | import + tag swap; novaReactions preserved | WIRED | Import line 92, components register line 219, template tag line 28; novaReactions import line 96 byte-identical |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
| ----------- | ----------- | ----------- | ------ | -------- |
| **PICK-01** | 151-07 | User kann VirtuProf-Skin in PersonalSettings über Dropdown auswählen | SATISFIED | NcSelect picker row in PersonalSettings.vue:106-115; deployed bundle contains `Charakter-Auswahl`. Visual confirmation deferred per user choice (manual walkthrough). |
| **PICK-02** | 151-04 | Skin-Auswahl wird pro User in NC user_config persistiert (`learning.virtuprof_skin`) | SATISFIED | VirtuProfController.php read line 183 + write line 386; PHPStan clean; deployed to relay (grep verified). Live API round-trip block in test-api.sh:691-697 (execution requires ADMIN_PASS — Plan 04 documented as deferred). |
| **PICK-03** | 151-01, 151-05, 151-07 | VirtuProf rendert conditional via SkinRenderer-Dispatcher | SATISFIED | SkinRenderer.vue dispatches per skinId (lines 47-52); VirtuProf.vue swap complete; SkinRenderer.test.js 5/5 green incl. nova/prof_lern_classic/architect dispatch tests |
| **PICK-04** | 151-01, 151-03, 151-05 | Skin-Wechsel reactive ohne Page-Reload (Pinia + `:key`-Remount) | SATISFIED | `:key="skinId"` SkinRenderer.vue:4; Pinia skinStore single source of truth (skinStore.js); PICK-04 vitest "remounts on skin change" green |
| **PICK-05** | 151-01, 151-03, 151-05 | Fallback auf Nova bei ungültiger/entfernter skinId | SATISFIED | skinStore.js:24-29 coerces non-string/null/unknown → 'nova'; ALLOWED_SKINS allowlist in VirtuProfController.php:23-29 + normalizeSkin fallback. PICK-05 vitest tests green |
| **CLASSIC-01** | 151-06 | VirtuProfAvatar.vue restauriert (HEAD per Reality Check #1) als ProfLernAvatar.vue | SATISFIED | git mv preserves history; component renamed to `ProfLernAvatar`; old file removed; CLASSIC-01 test (file existence + name match) green |
| **CLASSIC-02** | 151-01, 151-06 | Prof. Lern Classic features: Buch, Blick-Folge, Arm-Wave (1.2s auto-hide), Fragezeichen | SATISFIED | All 4 features present in ProfLernAvatar.vue (book group line 29, pupils tracking line 54+147, playWave + 1.2s setTimeout in onAvatarClick, question mark preserved). 7/7 ProfLernAvatar.test.js green. Visual fidelity (subjective) deferred per user. |
| **CLASSIC-03** | 151-01, 151-02, 151-06 | prof_lern_classic im Picker verfügbar | SATISFIED | characters.js:56-71 entry with user_selectable:true + category:'classic'; META-03 filter test asserts `['nova', 'prof_lern_classic']` exactly |
| **CLASSIC-04** | 151-01, 151-03 | Default-Skin für neu registrierte User (read-path only in 151) | SATISFIED | skinStore default 'nova' (Zero-Change-Default); loadFromServerPayload leaves default when payload missing skin key. Write-path "default to prof_lern_classic for new users" deferred to Phase 153 MIGR-01 per PLAN scope |
| **META-01** | 151-01, 151-02 | characters.js + 3 neue Felder (user_selectable, category, preview_thumbnail_svg) | SATISFIED | All 14 entries have 3 fields (grep verified); META-01 vitest green |
| **META-02** | 151-01, 151-02 | Additiver Default — bestehende 12 Charaktere unverändert | SATISFIED | META-02 vitest checks defaults (campaign + user_selectable:false) on the 12 existing campaign entries; spot-checks palette/silhouette/states unchanged. CharacterAvatar.test.js (10/10) still green = no Campaign-code regression |
| **META-03** | 151-01, 151-03, 151-07 | SkinPicker filtert nach `user_selectable === true` | SATISFIED | `availableSkins` getter in skinStore.js:21; consumed by skinOptions computed in PersonalSettings.vue:468; META-03 vitest green |

**Coverage:** 12/12 requirements satisfied. No orphaned IDs (REQUIREMENTS.md lines 142-157 list exactly these 12 against Phase 151).

### Anti-Patterns Scanned

| File | Line | Pattern | Severity | Impact |
| ---- | ---- | ------- | -------- | ------ |
| (none found) | — | — | — | — |

Quick scans:
- No `@vueuse/core` imports anywhere in `app/src/` or `app/package.json` (zero-deps decision held)
- No TODO/FIXME/PLACEHOLDER markers in any of the 7 modified files
- No `return null` / `=> {}` empty-implementation stubs
- ESLint clean per all 7 SUMMARYs (exit 0 across PersonalSettings, VirtuProf, SkinRenderer, ProfLernAvatar, characters.js, skinStore.js)
- PHPStan Level 5 clean on VirtuProfController.php

### Test Suite Status

- Pre-Phase-151 baseline: 1009 tests
- Phase 151 added: skinStore (9) + SkinRenderer (5) + ProfLernAvatar (7) + characters (6) = 27 new
- Current: **1036/1036 GREEN across 73 test files** (verified by `npm run test -- --run`, duration 5.06s)
- Delta: +27 (matches expectation 1036+)
- Zero regressions

### Deploy Status

- `./scripts/deploy-prod.sh --full` executed (per task context); confirmed via:
  - `VirtuProfController.php` in container has 2× `virtuprof_skin` (matches workstation copy)
  - `learning-personal-settings.js` deployed bundle contains `Charakter-Auswahl` (1×) + `prof_lern_classic`
  - `learning.js` chunk also contains `prof_lern_classic`
  - NC status: 33.0.2.2, maintenance:false, needsDbUpgrade:false, HTTP 302 (login redirect = healthy)

---

## Known Deferred Manual Items

These items are NOT gaps — they are explicitly deferred per user choice (Phase 150 Plan 06 pattern repeated: "bugs sucht User ad-hoc post-deploy"). Listed for transparency and future ad-hoc bug-hunt sessions:

1. **PICK-01 visual** — PersonalSettings "Charakter-Auswahl" row appearance + dropdown shows exactly 2 options (nova + Prof. Lern)
2. **PICK-04 mid-session swap** — Avatar swap visible without page reload after dropdown change
3. **PICK-02 hard-reload persistence** — Save → reload → selection persisted (round-trip end-to-end)
4. **CLASSIC-02-visual** — Subjective fidelity to v2.6.1 mascot ("the figure from the beginning") on relay
5. **CLASSIC-02-gaze cursor smoothness** — Frame-by-frame smoothness of pupil tracking, no jitter/lag
6. **CLASSIC-02-wave arc trajectory** — Arm-wave looks organic, not robot-twitch
7. **Cross-browser remount semantics (PICK-04)** — Chrome + Firefox + Safari skin-switch parity
8. **Live `bash scripts/test-api.sh` skin round-trip** — Plan 04 SUMMARY pre-acknowledged: assertion block exists at lines 691-697 but execution requires `ADMIN_PASS` env var; runs on next credentialed Gate-2 invocation

**Walkthrough URL:** https://devcloud.andrestiebitz.de/ (login as test user → PersonalSettings → "Charakter-Auswahl")

---

## Gaps Summary

**No gaps.** All 12 requirement IDs satisfied via three-layer architecture:
- **Backend** — VirtuProfController persists `virtuprof_skin` via NC user_config, exposes via `/api/virtuprof/state` (Pattern A hydration)
- **Store** — Pinia `skinStore` is single source of truth, allowlist coercion to nova
- **Component** — SkinRenderer polymorphic dispatcher (NovaDock / ProfLernAvatar / CharacterAvatar) with `:key` remount; ProfLernAvatar restored as proof-case
- **UI** — PersonalSettings picker row populated from `useSkinStore().availableSkins`

**Non-breaking guarantees verified:**
- novaReactions import line in VirtuProf.vue byte-identical (Phase 150 Plan 04 contract held)
- No @vueuse/core dependency added (v4.4.0 zero-deps decision held)
- All 12 existing characters.js entries unchanged at the field-level (META-02 regression guard)
- CharacterAvatar.test.js 10/10 still green (no Campaign-code regression)

**Ready for Phase 152** (Three Archetype Presets) — VirtuProfController allowlist already pre-allows `theoretiker`, `kosmologe`, `popularisierer`; SkinRenderer's CharacterAvatar branch handles them automatically (no dispatcher change needed).

---

_Verified: 2026-04-25_
_Verifier: Claude (gsd-verifier)_

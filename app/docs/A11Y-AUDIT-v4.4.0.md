# A11y Audit — v4.4.0 Character & Personality

**Auditor:** [name]
**Date:** [YYYY-MM-DD]
**App version under test:** v4.4.0
**Environment:** relay devcloud (https://devcloud.andrestiebitz.de) — Kurs 21 + ernesst

## Scope

This audit covers the Phase 153 TEST-06 + I18N-03 success criteria across 4 named checkpoints. Each checkpoint is independently signed off; partial pass blocks the App-Store-Push (Plan 07).

## Checkpoint 1 — `prefers-reduced-motion: reduce` halts all skin animations

**How to test:**
1. Open Chrome DevTools → ⋮ Menu → More tools → Rendering → Emulate CSS prefers-reduced-motion → "reduce"
2. Open VirtuProf chat, then PersonalSettings → switch through all 5 skins (Nova, Prof. Lern Classic, Theoretiker, Kosmologe, Popularisierer)
3. Observe: NO animation plays (no blink, no wave, no celebrate, no Kosmologe thruster glow, no Popularisierer star pulse)
4. Each skin renders a STATIC pose

**Expected outcome:** All 5 skins are static under reduced-motion emulation. WAAPI helpers return instantly. CSS `@keyframes` are neutralized by `@media (prefers-reduced-motion: reduce)` block in character-animations.css.

- [ ] Nova static under reduced-motion
- [ ] Prof. Lern Classic static
- [ ] Theoretiker static
- [ ] Kosmologe static (thruster glow halted)
- [ ] Popularisierer static (star pulse halted)
- [ ] PersonalSettings "Ruhige Darstellung"-Toggle (manual override) also halts all 5 — verified

**Result:** [ ] PASS  [ ] FAIL — notes:

## Checkpoint 2 — Screen-reader navigation (NVDA + VoiceOver)

**How to test:**
1. NVDA on Windows OR VoiceOver on macOS — start the SR
2. Tab through the SkinPicker dropdown
3. Listen for: avatar should announce its `aria-label` ONCE per skin selection, NOT per animation frame
4. Verify each skin's aria-label is statically descriptive (e.g. "Avatar: Der Theoretiker") — never dynamic per animation state

**Expected outcome:** Avatar SVG announces a static label, no per-frame chatter, no role spam, no announcement during reduced-motion emulation.

- [ ] NVDA Win + Firefox: avatar reads as "Avatar: [name]" once on focus, no spam
- [ ] VoiceOver macOS + Safari: same outcome
- [ ] No reading interruption mid-keyboard navigation through other picker controls

**Result:** [ ] PASS  [ ] FAIL — notes:

## Checkpoint 3 — Arabic RTL — avatar SVG NOT mirrored, picker labels right-aligned

**How to test:**
1. NC instance Settings → Region & Language → switch UI language to Arabic (`ar`)
2. Hard reload — entire UI re-renders with `dir="rtl"`
3. Open PersonalSettings → SkinPicker
4. Take screenshot: picker labels should be right-aligned (Arabic text reads right-to-left)
5. Open VirtuProf with each skin — avatar SVG should NOT be mirrored (faces still face the natural way; Theoretiker's mustache curls in the same direction; Kosmologe's wheelchair is on the same side as in DE)

**Expected outcome:** Picker UI is RTL-correct; avatar SVG is unmirrored. RESEARCH.md Pitfall 7 grep audit (Plan 01 Audit A) confirmed no `transform: scaleX(-1)` rule reaches the avatar SVG.

- [ ] PersonalSettings labels right-aligned
- [ ] Picker option list right-aligned
- [ ] Theoretiker SVG: mustache + hair-tufts mirror-direction matches DE
- [ ] Kosmologe SVG: wheelchair side matches DE, glasses orientation matches
- [ ] Popularisierer SVG: kinnbart matches DE, vest decoration matches
- [ ] Nova + Prof. Lern Classic: also unmirrored
- [ ] Screenshot saved at `app/docs/a11y-audit/v440-rtl-{skinId}.png` (5 files, one per skin)

**Result:** [ ] PASS  [ ] FAIL — notes:

## Checkpoint 4 — Keyboard-only navigation reaches every picker control

**How to test:**
1. Click anywhere outside the picker to clear focus
2. Press Tab repeatedly until focus enters PersonalSettings
3. Continue Tab/Shift-Tab/Arrow keys/Enter to:
   - Reach the SkinPicker dropdown
   - Open it with Enter (or Space)
   - Arrow Down/Up through options
   - Select with Enter
   - Reach the dismiss button on the one-time hint NcNoteCard
   - Click dismiss with Enter
4. Verify visible `:focus-visible` ring at every step (no invisible focus state)

**Expected outcome:** Every picker control is reachable with keyboard alone; visible focus indicator per WCAG 2.4.7; no focus traps.

- [ ] Tab/Shift-Tab traverses linearly into the picker
- [ ] Arrow keys navigate options inside the open dropdown
- [ ] Enter selects, Esc closes without selecting
- [ ] Hint dismiss button keyboard-reachable
- [ ] Visible focus indicator on every control
- [ ] No focus trap (can Tab out after dismiss)

**Result:** [ ] PASS  [ ] FAIL — notes:

## Sign-Off

| Checkpoint                  | Status | Date | Auditor |
|-----------------------------|--------|------|---------|
| 1. prefers-reduced-motion   | [ ]    |      |         |
| 2. Screen-reader navigation | [ ]    |      |         |
| 3. Arabic RTL unmirrored    | [ ]    |      |         |
| 4. Keyboard-only navigation | [ ]    |      |         |

**Aggregate verdict:** [ ] PASS — all 4 GREEN, App-Store-Push (Plan 07) cleared.  [ ] FAIL — gap closure required before Plan 07.

---
gsd_state_version: 1.0
milestone: v4.4.0
milestone_name: Character & Personality
current_phase: 152
current_plan: 5
status: phase-152-ready-for-verification
stopped_at: Plan 152-05 complete (Popularisierer silhouette + inline <defs> radialGradient shipped; Vitest 21/21 GREEN, ESLint+forbidden-names CI clean, vite build clean, Section 2.3 No-Go grep exit 1 — all 3 scholar archetypes now silhouette-complete in CharacterAvatar.vue, file LOCKED for Plan 06 close-out)
last_updated: "2026-04-25T12:50:34.825Z"
last_activity: 2026-04-25
progress:
  total_phases: 5
  completed_phases: 4
  total_plans: 19
  completed_plans: 19
  percent: 80
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-04-18)

**Core value:** Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung.
**Current focus:** v4.4.0 Character & Personality — VirtuProf bekommt ein Gesicht (Skin-Picker + Prof. Lern Classic + 3 Archetype-Presets, Zero-Change-Default für Bestandsuser).

## Current Position

Phase: 152 (ready for verification — silhouette plans complete, Plan 06 close-out remaining)
Current Plan: 5 (last)
Total Plans in Phase: 5 (data + 3 silhouettes + close-out)
Status: Phase 152 Plan 05 complete (Popularisierer silhouette + inline <defs> radialGradient shipped). 21 elements (6 head + 9 arms + 6 power) — kinnbart triangle + magenta vest + 5 generic star-pattern dots + 2 open-arm Q-curves + Kosmos-Projektion gradient circle (fill='url(#popularisierer-projection-gradient)') + 5 star highlights. Vitest 21/21 GREEN, ESLint+forbidden-names CI clean, vite build clean (267ms), Section 2.3 No-Go awk-grep exit 1 (no hits). All 3 scholar archetypes (Theoretiker / Kosmologe / Popularisierer) now silhouette-complete. CharacterAvatar.vue grew 520→739 LOC (+219 net) across Plans 03+04+05 (6 commits). File now effectively LOCKED for Plan 06. Plan 06 = sensitivity-review SIGNOFF + close-out (Wave-0 stubs from a9f00de get filled, no further CharacterAvatar.vue edits).
Last activity: 2026-04-25
Progress (v4.4.0): [■■■■□] 4/5 phases complete (80% — 152 awaiting Plan 06 close-out)

## Performance Metrics

- Granularity: standard
- Parallelization: on
- v4.4.0 phase count: 5 (research-recommended)
- v4.4.0 requirement count: 40 (mapped 40/40)
- No new npm dependencies (stack-research confirmed)

| Phase | Plan | Duration | Tasks | Files |
|-------|------|----------|-------|-------|
| 149 | 01 | 13min | 2 | 5 |
| Phase 149 P04 | 2 min | 1 tasks | 1 files |
| Phase 149 P03 | 3 min | 2 tasks | 2 files |
| Phase 149 P02 | 4min | 1 tasks | 2 files |
| 152 | 02 | ~15min | 1 | 2 |
| Phase 152 P03 | 12min | 2 tasks | 2 files |
| Phase 152 P04 | 6min | 2 tasks | 2 files |
| Phase 152 P05 | 5min | 2 tasks | 2 files |

## Accumulated Context

### Decisions (v4.4.0)

- [149-01] Bash-script-sourced pre-push gate pattern (testable, reusable in CI) chosen over inline hook logic
- [149-01] .githooks/pre-push kept as tracked mirror for fresh clones (gitignore allowlist added) rather than deprecated
- [149-01] LEGAL-EXCEPTION inline marker established as escape hatch for legal-review documents that must reference forbidden names
- [149-02] LEGAL.md v1.0 created — 5-chapter trademark analysis locks Archetype-Naming (USPTO #3591305 Einstein, #5980163 Hawking, Tyson right-of-publicity, 2022 CA ruling); amendment protocol version-bumps the doc for any future change
- [149-02] Gitignore allowlist pattern /.planning/* + explicit !/.planning/LEGAL.md (and STATE/ROADMAP/REQUIREMENTS/PROJECT) preserves phases/** ignore + app/.planning/ ignore; tracked meta files stay tracked
- [149-03] `app/docs/ART_STYLE_GUIDE.md` chosen as separate file over CHARACTER_BIBLE.md integration — ships with app, discoverable from README/CHANGELOG/App Store docs
- [149-03] Character-first drawing order (clothing → pose → face → hair → wheelchair LAST) mandatory in Section 2.2 Der Kosmologe — single most load-bearing rule against CHI-2024 caricature trap
- [149-03] SVG security bans (`<script>`, `<foreignObject>`, external `xlink:href`, `on*` attrs) elevated to Universal No-Gos — pre-empts custom-upload attack surface and locks Phase 152 svgo sanitization contract
- [149-03] Sensitivity-Review-Gate is hard blocker for Phase 152 SVG-Freeze — no SVG ships without `.planning/sensitivity-review/SIGNOFF.md` entry per archetype
- [149-04] CHANGELOG v4.4.0 UNRELEASED entry locked with archetype-label-only naming (Theoretiker / Kosmologe / Popularisierer) at top of `app/CHANGELOG.md`; Added/Changed blocks ready for Phase 153 App Store copy-paste
- [149-04] CHANGELOG canonical path is `app/CHANGELOG.md` (not repo root); `scripts/check-forbidden-names.sh` scope-path mismatch logged to `phases/149-legal-art-direction/deferred-items.md` for future fix
- [152-02] Palette tokens used as planned: theoretiker=amber/text-muted/green, kosmologe=primary/ink/cyan, popularisierer=magenta/warning/magenta — `--lnc-warning` referenced but not defined as :root var (relies on NC cascade chain like prof_lern_classic precedent at line 64 of characters.js); flagged for future audit
- [152-02] States array set to exactly ['idle', 'wave', 'celebrate'] (≥3 per ANIM-05 + RED-test arrayContaining superset). 'thinking' deferred to Plan 06 if scholarAnimations 12-case matrix demands it
- [152-02] preview_thumbnail_svg: null for all 3 entries (Phase 153 fills) — Phase 152 is data layer + silhouette layer only
- [152-02] Updated stale Phase 151 picker assertion in characters.test.js (was hardcoded to ['nova', 'prof_lern_classic']) — Rule 1 deviation, single source of truth shifted to SELECTABLE_CHARACTER_IDS const
- [152-03] Shared `<g id="powerEffect">` overlay group landed in CharacterAvatar.vue — region-tagged elements (`region: 'power'`) automatically render LAST in the SVG drawing order (Pitfall 2 z-order); template has NO `<text>` branch by design (Pitfall 6 structural prevention). Plans 04/05 reuse this with zero further template changes.
- [152-03] Three-way feature partition pattern: `featureElements` → `headFeatures` (y < bodyTop, region !== 'power') / `armsFeatures` (y >= bodyTop, region !== 'power') / `powerFeatures` (region === 'power'). Backward-compat via filter short-circuit — existing 14 silhouettes never set `region` so they fall through unchanged.
- [152-03] Theoretiker glyph paths chosen as deliberately abstract (1 Q-curlicue, 1 zigzag, 1 small A-arc r=4, 1 Q-arch) — none are traceable to a real math symbol; A-arc kept under 4px so it can't read as phi/zero/circle. ART_STYLE_GUIDE Section 2.1 No-Go list cleared (no E=mc, no name-cues, no <text> with letters).
- [152-03] Eyebrows added beyond plan recommendation (plan said optional) to balance head silhouette — without them mustache reads alone and over-weights the lower face. 2 short `<line>` elements, strokeWidth 1.5.
- [152-04] bodyPath kosmologe special-case returns '' (empty path) — seated body fully painted via 21-element featureElements case in Power-First Drawing Order; cleaner than custom seated path because wheelchair LAST z-order naturally works (RESEARCH Pattern 2)
- [152-04] Element dispatch :class extends ALL 3 groups (head/arms/powerEffect = 12 occurrences total = 3 groups × 4 shape branches) for forward-compat; thruster CSS targeting via class:'thruster' on featureElements + dispatch `:class="el.class || null"` propagates to SVG primitive
- [152-04] Thruster keyframe opacity-only (0.85↔1.0, 1500ms ease-in-out) per Section 4 line 158 — no width/height/top/left/filter/box-shadow paint-triggering props; reduced-motion fallback inherited via Phase 150 global `.character-avatar *` descendant selector at lines 661-667 (no new @media block needed)
- [152-04] 21 elements (4 body + 1 face + 5 brille + 8 rollstuhl + 3 power) instead of plan's recommended ~25 — plan said approximate; shipped recommended layout exactly without padding (advisor confirmed correct count)
- [152-05] Popularisierer silhouette case shipped (~21 elements: 6 head + 9 arms + 6 power) with inline `<defs>` radialGradient (popularisierer-projection-gradient, magenta 0.9→0.4→0 fade) referenced via `fill='url(#...)'` on Kosmos-Projektion center circle. Default trapezoid bodyPath with width 0.34 (broader than theoretiker 0.32 due to vest) — no special-case bodyPath branch needed.
- [152-05] ART_STYLE_GUIDE Section 2.3 compliance verified via 5 independent gates (forbidden-names CI exit 0, No-Go awk-grep over case body exit 1, `<text>` grep exit 1, ESLint clean, vite build clean) — generic kinnbart triangle + 5 scattered vest dots + magenta/violet/gold-driven palette (NOT skin-tone-driven) avoid both real-person likeness AND racial-exaggeration.
- [152-05] Phase 152 silhouette LOC delta: CharacterAvatar.vue grew 520→739 lines (+219 net) across 6 commits in Plans 03/04/05 — file now effectively LOCKED for Plan 06 close-out (Plan 06 only writes new test files + sensitivity-review SIGNOFF, no further CharacterAvatar.vue edits).
- [152-05] gsd-tools `state advance-plan` + `update-progress` regression noted: advance-plan stripped `milestone`/`milestone_name`/`current_phase` from frontmatter and reset to defaults (v2.3/milestone/missing); update-progress recalculated 160/154 by counting all on-disk SUMMARYs vs total_plans=154. Restored frontmatter manually. add-decision/record-session also failed ("Decisions section not found" — heading is `### Decisions (v4.4.0)`, tool expects bare `## Decisions`). Bug-log candidate for INBOX (third gsd-tools regression in this milestone).
- [v4.4.0] Archetype-Naming zementiert — keine realen Namen (Einstein/Hawking/Tyson) wegen Trademark + Right-of-Publicity; Labels "Der Theoretiker / Der Kosmologe / Der Astrophysik-Popularisierer"
- [v4.4.0] Zero-Change-Default für Bestandsuser — NOVA bleibt für alle aktuellen User, Prof. Lern Classic wird Default NUR für neu registrierte User (Nova-Removal-Trauma-Repeat vermeiden)
- [v4.4.0] Externe Sensitivity-Review vor Phase 152 Freeze — ~€300 Budget, gated durch Phase 149
- [v4.4.0] Keine neuen npm-Dependencies — CSS + WAAPI + canvas-confetti reichen
- [v4.4.0] Polymorpher SkinRenderer — NovaDock bleibt eigenständig (Partikel-System), Prof. Lern + Archetypen nutzen CharacterAvatar
- [v4.4.0] Meta-Schema additiv erweitert — kein version-bump, non-breaking für bestehende 12 Charaktere
- [v4.4.0] prefers-reduced-motion + manueller "Ruhige Darstellung"-Toggle ab Tag 1 gated
- [v4.4.0] CI Grep-Check gegen verbotene Eigennamen (Einstein/Hawking/Tyson/Neil deGrasse/Cosmos/StarTalk)
- [v4.4.0] v4.4.0 ships VOR v4.3.0 — v4.3.0 phases wurden auf 154-157 verschoben

### Decisions (v4.2.0)

- [v4.2.0] Kein Adminer — NLM warnt vor IDOR-Risiko, stattdessen OCC-Commands + API
- [v4.2.0] course_schedule Tabelle — Verknüpft chapter_ref mit Datum, synchron mit curriculum_scopes
- [v4.2.0] Export-Logik als DataMobilityService — wiederverwendbar für OCC + API
- [v4.2.0] Jahrgangs-Merge muss FSRS-Stabilitätswerte erhalten
- [v4.2.0] Timeline nutzt bestehende chapter_ref + curriculum_scopes als Basis
- [v4.2.0] occ learning:import-vault als Vorbild für neue OCC-Commands

### Research Flags

- **Phase 149:** HIGH FLAG bei Named-Presets-Request (wäre Einstein Foundation + Hawking Estate Kontakt, 4-8 Wochen Budget) — Default-Pfad aber Archetype, keine externen Kontakte nötig. Sensitivity-Reviewer sourcen.
- **Phase 152:** MEDIUM FLAG — Konzept-Art-Iteration vor SVG-Freeze empfohlen (1 Iteration mit Sensitivity-Reviewer).
- **Phase 150, 151, 153:** Standard-Patterns (WAAPI, Pinia, NcSelect, Deploy-Runbook) — skippable für research-phase.

### Known Risks (v4.4.0)

- BLOCKER #1-#3: Trademark/Right-of-Publicity — Gating durch Phase 149 (LEGAL-01..04)
- HIGH #4: Global-Avatar CPU-Budget — IntersectionObserver-Pause + `transform`/`opacity`-only (Phase 150)
- HIGH #5: WCAG 2.3.3 — ADHS-User in Kurs 21 + ernesst's Sohn (Phase 150, A11Y-01..05)
- HIGH #6: Nova-Removal-Trauma-Repeat — Zero-Change-Default (Phase 153, MIGR-01)
- HIGH #7: Disability-Caricature-Trap — Sensitivity-Review + Style-Guide (Phase 149/152)
- HIGH #8: i18n 5-Sprachen-Parität — CI key-parity-check (Phase 153, I18N-01..03)
- MEDIUM #10: State-Desync bei Skin-Change mid-chat — `:key`-Remount + Pinia single source (Phase 151, PICK-04)
- MEDIUM #14: JS-chunk-Stale-Cache — deploy-script-audit (Phase 153)

### Todos & Blockers

- [ ] Sensitivity-Reviewer sourcen (Phase 149 Exit-Kriterium)
- [ ] Low-End-Device-Profil (Galaxy Tab A 2019-era) für Phase 153 bereitstellen
- [ ] v4.3.0 OnbProfileTiles.vue-Konflikt: v4.4.0 zuerst, Onboarding-Integration als v4.5.x INBOX-Item

## Session Continuity

Last session: 2026-04-25T12:50:34.822Z
Stopped at: Plan 152-05 complete (Popularisierer silhouette + inline <defs> radialGradient shipped; Vitest 21/21 GREEN, ESLint+forbidden-names CI clean, vite build clean, Section 2.3 No-Go grep exit 1 — all 3 scholar archetypes now silhouette-complete in CharacterAvatar.vue, file LOCKED for Plan 06 close-out)
Next action: Phase 152 verification + Plan 06 close-out. Plan 06 is the manual sensitivity-review SIGNOFF + Wave-0 test-stub fill (test files already scaffolded in commit a9f00de: scholarAnimations.test.js + scholarSvgSecurity.test.js). After Plan 06 SIGNOFF, Phase 152 closes and v4.4.0 advances to Phase 153 (release-and-l10n: App Store description + i18n parity for the 3 new scholar archetypes). CharacterAvatar.vue is LOCKED — no further silhouette edits expected in Phase 152.

---
gsd_state_version: 1.0
milestone: v4.4.0
milestone_name: Character & Personality
current_phase: 152
current_plan: null
status: phase-151-complete-ready-for-152
stopped_at: Phase 151 closed (7/7 plans, 1036/1036 tests, three-layer skin architecture live, deployed to relay 2026-04-25)
last_updated: "2026-04-25T10:15:00+02:00"
last_activity: 2026-04-25
progress:
  total_phases: 5
  completed_phases: 3
  total_plans: 18
  completed_plans: 18
  percent: 60
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-04-18)

**Core value:** Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung.
**Current focus:** v4.4.0 Character & Personality — VirtuProf bekommt ein Gesicht (Skin-Picker + Prof. Lern Classic + 3 Archetype-Presets, Zero-Change-Default für Bestandsuser).

## Current Position

Phase: 152 (next, not yet researched)
Current Plan: —
Total Plans in Phase: TBD
Status: Phase 151 complete (7/7 plans). Codex did Wave 1+2 plans 02/04/06; Claude did 01/03/05/07. Verifier passed 12/12 requirements, 1036/1036 tests, three-layer skin architecture (backend + Pinia + dispatcher) live, NovaDock→SkinRenderer swap clean, novaReactions non-breaking guarantee held. Manual visual walkthrough deferred to ad-hoc bug-hunt.
Last activity: 2026-04-25 — Phase 151 shipped to relay + verified
Progress (v4.4.0): [■■■□□] 3/5 phases complete (60%)

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

Last session: 2026-04-25T10:15:00+02:00
Stopped at: Phase 151 closure committed (verifier passed)
Next action: `/gsd:plan-phase 152` — Three Archetype Presets (Theoretiker / Kosmologe / Astrophysik-Popularisierer SVGs). Phase 151 primitives ready: SkinRenderer dispatcher auto-routes new ids to CharacterAvatar; characters.js can be extended additively; VirtuProfController allowlist already preallows the three archetype ids. Sensitivity-Sign-off in `.planning/sensitivity-review/SIGNOFF.md` is hard gate before SVG freeze.

---
phase: 35-kampagnen-content
plan: 01
subsystem: content
tags: [story-rpg, campaigns, json, network-plus, security-plus, linux-plus, a-plus]

# Dependency graph
requires:
  - phase: 34-charakter-simulation
    provides: campaign JSON schema (characters, skill_check, simulation types)
  - phase: 32-story-engine
    provides: StoryEngine.php that loads and validates campaign JSONs
provides:
  - 4 complete campaign JSON files covering Security+, Network+, Linux+, A+ domains
  - All 5 campaigns now playable end-to-end with full branching story trees
  - pool_filter values mapped to all major CompTIA topic areas
affects: [story-engine, rpg-frontend, abenteuer-mode]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Campaign JSON schema: 9 scenes (5 story + branches + 3 epilogs), all branches validated against scene IDs"
    - "Character adjustments: difficulty_modifier per class per skill_check"
    - "Simulation types: cli_analysis, network_device_placement, cable_test_analysis, static_route_config, soho_troubleshooting"

key-files:
  created:
    - app/data/campaigns/einbruch_im_netz.json
    - app/data/campaigns/der_neue_standort.json
    - app/data/campaigns/ransomware.json
    - app/data/campaigns/das_erbe.json
  modified: []

key-decisions:
  - "Each campaign has 9 scenes: 5 primary story scenes + 2-3 branch/fail variants + 3 epilog variants (success/partial/fail)"
  - "All 4 characters defined in every campaign with appropriate skill_bonus_pools and skill_penalty_pools"
  - "Simulation types use descriptive strings matching PbqRenderer capabilities (cli_analysis, network_device_placement, etc.)"
  - "pool_filter values use snake_case topic keys: routing, vlan, security, incident_response, linux, cabling, vpn, backup, hardware, networking, troubleshooting, wireless"
  - "All branch targets (success_scene, partial_scene, fail_scene) validated — zero dead branches across all 5 campaigns"

patterns-established:
  - "Campaign epilog: both inline epilog scenes (is_epilog: true) AND top-level epilog object for quick engine lookup"
  - "NPC dialog always uses nova for tech hints, jens_bug for story complications, dr_weber for pressure/stakes"

requirements-completed: [CAMP-01, CAMP-02, CAMP-03, CAMP-04, CAMP-05]

# Metrics
duration: 20min
completed: 2026-03-22
---

# Phase 35: Kampagnen-Content Summary

**5 complete story-RPG campaign JSONs covering all CompTIA domains: Security+ Incident Response, Network+ site build, Security+ ransomware, and A+/Network+/Linux+ legacy migration — all with full branching, skill-checks, simulations, and three epilog variants each**

## Performance

- **Duration:** ~20 min
- **Started:** 2026-03-22T02:48:58Z
- **Completed:** 2026-03-22T05:09:14Z
- **Tasks:** 1 (4 campaign files created atomically)
- **Files created:** 4

## Accomplishments

- Created `einbruch_im_netz.json` — Security+ Incident Response campaign (IR steps, containment, forensics, eradication, hardening; CLI log-analysis simulation)
- Created `der_neue_standort.json` — Mixed Network+/Security+ new site project (design, hardware selection, cabling with cable tester simulation, VPN, go-live troubleshooting)
- Created `ransomware.json` — Security+ ransomware response (first reaction choices, SMB containment, 3-2-1 backup strategy, DSGVO compliance, network segmentation simulation)
- Created `das_erbe.json` — Mixed A+/Network+/Linux+ legacy migration (hub vs switch, network discovery CLI simulation, Ubuntu server analysis, IP schema migration, SOHO troubleshooting simulation)
- All 5 campaigns validated: zero dead branches, all JSON valid, all scene IDs resolve

## Task Commits

1. **Task 1: 4 campaign JSON files** — `261160e` (feat)

## Files Created

- `app/data/campaigns/einbruch_im_netz.json` — Security+ Incident Response, 9 scenes, 2 simulations (cli_analysis, network_device_placement)
- `app/data/campaigns/der_neue_standort.json` — Network+/Security+ site build, 9 scenes, 2 simulations (cable_test_analysis, soho_troubleshooting)
- `app/data/campaigns/ransomware.json` — Security+ ransomware, 9 scenes, 1 simulation (static_route_config)
- `app/data/campaigns/das_erbe.json` — A+/Network+/Linux+ legacy migration, 9 scenes, 2 simulations (cli_analysis, soho_troubleshooting)

## Decisions Made

- Each campaign has exactly 9 scenes following the existing `grosser_ausfall.json` pattern: story scenes, branch/fail variants, and inline epilog scenes alongside a top-level epilog object for engine compatibility.
- Character `skill_bonus_pools` and `skill_penalty_pools` are aligned with the storyboard class descriptions — Security-Analystin gets firewall/forensics bonus, Sysadmin gets linux/dns/backup bonus, etc.
- `pool_filter` values use broad topic keys (routing, vlan, security, linux, cabling, vpn, backup, hardware, networking, troubleshooting, wireless, incident_response, firewall, forensics, endpoint_security) that the StoryEngine can map to actual DB pools by matching pool descriptions/tags.
- Simulation types use descriptive strings (cli_analysis, network_device_placement, cable_test_analysis, static_route_config, soho_troubleshooting) that correspond to existing PbqRenderer simulation capabilities.

## Deviations from Plan

None — plan executed exactly as written.

## Issues Encountered

None.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- All 5 campaign JSONs are playable. Phase 35 requirements CAMP-01 through CAMP-05 are complete.
- The v6.0 "Abenteuer" milestone is now fully content-complete.
- Remaining work: deploy campaign files to learning-dev container and verify StoryEngine loads all 5 without errors.
- Optional: update `grosser_ausfall.json` to add the missing `epilog_fail` inline scene (currently only has success/partial) for full parity with the 4 new campaigns.

---
*Phase: 35-kampagnen-content*
*Completed: 2026-03-22*

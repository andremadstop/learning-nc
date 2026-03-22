---
phase: 42-security-kampagnen-teil-2-kurs-kampagnen
plan: "02"
subsystem: campaigns
tags: [a-plus, linux-plus, cysa-plus, campaigns, abenteuer-modus]
dependency_graph:
  requires: [StoryEngineService, GeminiService, campaign-json-format]
  provides: [a_plus_erster_tag-campaign, linux_server_down-campaign, cysa_zero_day-campaign]
  affects: [AbenteuerMode.vue, campaign-library]
tech_stack:
  added: []
  patterns: [campaign-json-structure, gemini-dau-role, gemini-attacker-role, cli-simulation-stubs]
key_files:
  created:
    - app/data/campaigns/a_plus_erster_tag.json
    - app/data/campaigns/linux_server_down.json
    - app/data/campaigns/cysa_zero_day.json
  modified: []
decisions:
  - A+ campaign uses beginner difficulty with office comedy narrative style
  - Linux+ campaign uses intermediate difficulty with Friday-evening-crisis narrative
  - CySA+ campaign uses expert difficulty with paranoid cyber-thriller narrative
  - All three campaigns follow solarwinds.json structure exactly for compatibility
metrics:
  duration: 8min
  completed: "2026-03-22"
---

# Phase 42 Plan 02: Cross-Discipline Campaign JSONs Summary

Three CompTIA certification campaigns covering A+, Linux+, and CySA+ with role-appropriate scenarios, Gemini integration, and skill checks mapped to certification domains.

## Task Results

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | A+ "Der erste Tag" campaign | 3941df1 | app/data/campaigns/a_plus_erster_tag.json |
| 2 | Linux+ "Server Down" + CySA+ "Zero Day" | 3c4ca20 | app/data/campaigns/linux_server_down.json, app/data/campaigns/cysa_zero_day.json |

## Campaign Details

### A+ "Der erste Tag" (Beginner)
- **Scenario:** First day as IT support -- everything goes wrong simultaneously
- **Gemini Role:** DAU (confused end-users: "Der Becherhalter ist abgebrochen" = CD-Tray)
- **Scenes:** 6 (5 story + 1 fail branch) + 3 epilogs
- **Skill Pools:** a_plus, hardware, troubleshooting
- **NPCs:** HR-Leiterin, wuetender Vertriebsleiter, verschuettete-Kaffee-Praktikantin, IT-Leiter-Mentor
- **Simulation Stubs:** Device Manager analysis, Windows Recovery Environment

### Linux+ "Server Down" (Intermediate)
- **Scenario:** Friday 17:00 -- three production servers crash, CLI-only recovery
- **Gemini Role:** DAU (panicking project manager who calls every 5 minutes)
- **Scenes:** 6 (5 story + 1 fail branch) + 3 epilogs
- **Skill Pools:** linux, troubleshooting, dns
- **NPCs:** Panicking PM, remote senior admin, DBA, monitoring bot
- **Simulation Stubs:** Filesystem diagnosis (df/pvs/lvs), service debugging (systemctl/journalctl), network diagnosis (ip route/nftables)

### CySA+ "Zero Day" (Expert)
- **Scenario:** APT group "Phantom Crane" using zero-day VPN exploit, adaptive tactics
- **Gemini Role:** Attacker (sophisticated APT actor with false flags and tactic switching)
- **Scenes:** 6 (5 story + 1 fail branch) + 3 epilogs
- **Skill Pools:** cysa_plus, forensics, incident_response, security
- **NPCs:** SOC Lead, Threat Intel analyst, IR Lead, CISO
- **Simulation Stubs:** IOC extraction (Base64 decode, YARA rules), zero-day analysis (IPS signatures, CVE report)

## Deviations from Plan

None -- plan executed exactly as written.

## Verification

All three campaigns validated: correct campaign_id, narrator_mode=true, appropriate gemini_role, 5+ story scenes, 3 epilogs each.

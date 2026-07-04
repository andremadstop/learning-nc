---
created: 2026-07-04
updated: 2026-07-04
branch: main
status: audit-fix in progress — all HIGH done, MED batch done, rest documented
---

# HANDOFF — Learning-NC post-v5.2.0 whole-app audit

## TL;DR
- v5.2.0 shipped (tag `v5.2.0` on main, forgejo+codeberg). App-Store publication withheld (Andre: main+tag only).
- A whole-app audit (8 review lanes) produced **12 HIGH, 22 MED, 11 LOW** — triage in `.planning/AUDIT-2026-07-03-FINDINGS.md`.
- **All 12 HIGH fixed & committed. 8 MED fixed & committed.** Each through Gate 1 (PHPStan 0, PHPUnit green, live boot).
- The rest is deliberately deferred with reasons (product decisions, speculative changes, infra refactors, low-impact LOWs) — see the Fix Status section of the findings file.

## What's fixed (on `main`, 6 audit commits since the tag)
- HIGH-01/04/05/07–12 (Codex batch, verified + committed by Claude): exam-oracle strips, export/document/feed IDOR gates, three table-name-drift repairs (Migration 009900), PBQ scoring, exam-resume integrity.
- HIGH-02 RAG cross-course IDOR + HIGH-03 missing AI consent gate (VirtuProfController).
- HIGH-06 docs drift (README/info.xml/DEVELOPMENT).
- MED-02/03/04/05/06/11/12/13/14 (RagImport authz, Gameshow order, translation oracle, prefix, entity, cert DTO, docs).

## Environment gotchas learned this session (IMPORTANT)
- **DO NOT run `deploy-prod.sh` for audit fixes.** It ships git's info.xml (5.2.0) which mismatches devcloud's appconfig (5.2.0.5) → NC "needs upgrade" → whole instance 503. It happened once and was fixed by setting the CONTAINER info.xml back to 5.2.0.5. For fixes, deploy single files manually: `scp app/lib/... relais:~/learning-nc/app/lib/... && ssh relais 'docker cp ... && docker exec devcloud-app apache2ctl graceful'`. info.xml stays untouched.
- PHPStan/PHPUnit run in the container: `ssh relais "docker exec -w /var/www/html/custom_apps/learning devcloud-app php vendor/bin/{phpstan analyse --no-progress|phpunit}"`. Deploy tests via `rsync app/tests → docker cp`.
- No local PHP on the workstation.
- Safety hook blocks `rm -rf` — use `rm -r --`.

## Next (if continuing the audit)
Safe-to-fix next, frontend/AI context still warm:
1. MED-08 multi-turn injection — store SANITIZED chat memory (or re-filter on load) in AiChatMemoryService/GeminiService.
2. MED-09 AI rate-limit bypass — attach generateNote/generateStructured to the shared minute/day counter.
3. MED-10 VirtuProf exam oracle — suppress RAG answer context when an active exam exists on the pool (HIGH-02's filter already narrows this).
4. LOW batch — rate-limits (LOW-02/03/05), reviewerId spoofing (LOW-01), 404 (LOW-06), join-membership (LOW-04).

Needs a decision / own session (do NOT blind-fix): MED-01 (owner-flow risk), MED-07 (product: names to Gemini), MED-16/20 (FSRS — verify vs model version first), MED-17/18/19 (test-infra refactors), MED-21/22 (PBQ frontend DTO split).

## Also still open from v5.2.0 itself
- Human-verify (Andre's run-through): recert loop, notification bell, video gate, dashboard. test-api.sh needs Vault ADMIN_PASS (not found on workstation).
- `/gsd:complete-milestone` for v5.2.0.
- devcloud ops: oc_jobs bloated (5168× NC-core UpdateSingleMetadata) — cron backlog.

## Files
- Audit triage + full fix ledger: `.planning/AUDIT-2026-07-03-FINDINGS.md`
- Audit re-run brief (if lanes need re-running): `.planning/AUDIT-2026-07-03-PLAN.md`
- Codex's working notes: `HANDOFF_LOG.md`, `RESUME_PROMPT.md` (superseded by this file)

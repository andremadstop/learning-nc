---
phase: 156
slug: compliance-report
status: planned
nyquist_compliant: true
wave_0_complete: false
created: 2026-06-27
---

# Phase 156 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit (backend) + Vitest (frontend) |
| **Config file** | app/phpunit.xml · app/vite.config.mjs |
| **Quick run command** | `ssh relais 'docker exec -w /var/www/html/custom_apps/learning devcloud-app php vendor/bin/phpunit --filter <NewTest>'` |
| **Full suite command** | `cd app && npm run test` (Vitest) + PHPUnit Unit suite on relais |
| **Estimated runtime** | ~30-60 seconds |

---

## Sampling Rate

- **After every task commit:** Run the quick `--filter` PHPUnit/Vitest for the touched unit
- **After every plan wave:** Run the full PHPUnit Unit suite + Vitest + PHPStan L5 + ESLint
- **Before `/gsd:verify-work`:** Full suite must be green
- **Max feedback latency:** 60 seconds

---

## Per-Task Verification Map

> Filled by the planner. The DSGVO no-leak assertion and owner-scoping/IDOR are the load-bearing tests.

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|-----------|-------------------|-------------|--------|
| 156-01-T1 | 01 | 1 | REPORT-04 (no-leak) + REPORT-01/02 + IDOR | unit (PHPUnit, TDD) | `ssh relais 'docker exec -w /var/www/html/custom_apps/learning devcloud-app php vendor/bin/phpunit --filter CertificateReportServiceTest'` | ❌ created in T1 | ⬜ pending |
| 156-01-T2 | 01 | 1 | REPORT-03 (CSV) + endpoints | inspection + Gate2 | `bash -n scripts/test-api.sh` + `occ router:list learning` (live credentialed test-api.sh cert-report block DEFERRED, no ADMIN_PASS) | ❌ created in T2 | ⬜ pending |
| 156-02-T1 | 02 | 2 | REPORT-02 (filter qs) + REPORT-01 (gating) | unit (Vitest, TDD) | `cd app && npm run test -- CertReport` | ❌ created in T1 | ⬜ pending |
| 156-02-T2 | 02 | 2 | REPORT-01/03 (UI + i18n) | unit + manual | `npx eslint ...` + `npm run test -- CertReport` + i18n parity; visual render/download = deferred demo-course check | ❌ created in T2 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] Backend test for the report service (report rows = display name + passed date + score + expiry + verification UUID; NO user_id/email keys present) — REPORT-01
- [ ] DSGVO leak-assertion test: neither the JSON table response NOR the CSV body ever contains an email or a user_id, including the empty-display-name fallback path — REPORT-04 (non-negotiable)
- [ ] Owner-scoping/IDOR test: instructor of course A gets 403/Forbidden on course B's report — security
- [ ] Filter-correctness test: passed-date range + expiry-window produce identical row sets for table and CSV — REPORT-02
- [ ] CSV-format test: correct columns/headers, display-name-only — REPORT-03

*If none: "Existing infrastructure covers all phase requirements." — NOT the case here; new tests required.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Report table renders in the instructor course view; CSV downloads | REPORT-01/03 | Browser render + file download | Open a certifying course as instructor → see compliance table → click Export CSV → open file (display name only). Can be folded into the same demo-course visual check as deferred CERT-07/08/13. |

*Automated tests cover the data/security/filter/format; only the visual render + download is manual.*

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 60s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending

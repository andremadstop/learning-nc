# HANDOFF — Phase 157 Public-Verify (mid-execution)

**Stand:** 2026-06-27, Notbremse (Rechner-Shutdown). Branch `feature/v5.0.0-certification`.

## Wo wir stehen

**Phase 156 (Compliance-Report): ✅ COMPLETE + verified** (8/8 must-haves, REPORT-01..04 done). Abgeschlossen früher in dieser Session. VERIFICATION committed (`aa8427f`).

**Phase 157 (Public-Verify): 4 von 5 Plänen ausgeführt, 1 offen.**
Geplant + plan-checker-verified (passed, 5 Pläne/4 Wellen). Review-Gate (Codex Security + Gemini UX) lief vor der Planung → 8 Härtungs-Constraints in `157-CONTEXT.md` `<review_findings>`.

| Plan | Wave | Status | Commits |
|------|------|--------|---------|
| 157-01 revoked_at-Migration (Version009200, dormant) + Entity + cross-DB | 1 | ✅ DONE | 16404e0, 3196785, 69a96fa |
| 157-02 CertificateVerifyService (Sig+key-status+claim-binding+DSGVO-DTO) | 2 | ✅ DONE | 4638e69, 85b6321, 47941bc |
| 157-03 Revoke-Write (owner-gated, idempotent, active_idem_key=NULL) + Button | 2 | ✅ DONE | f51c3b3, 37e5e23, 5813849 |
| 157-04 PublicVerifyController + server-template verify.php + i18n×5 | 3 | ✅ DONE | 70c9695, b463bb1, d05d593, 94f0166, eeed1dc |
| **157-05 Playwright leak/reachability + cross-DB-GO + phase-gate** | 4 | ⛔ **NICHT GESTARTET** | — |

STATE.md: `milestone: v5.0.0` intakt, `completed_plans: 4`, `current_plan: 04`. Alle Gate-1 grün (PHPUnit/PHPStan L5/ESLint 0/i18n parity). Arbeitsbaum sauber bis auf vor-existierende `.planning/INBOX.md`-Mod (nicht von uns).

## NÄCHSTER SCHRITT (genau hier weitermachen)

1. **157-05 ausführen** (gsd-executor, Plan `.planning/phases/157-public-verify/157-05-PLAN.md`). Es schreibt den Playwright logged-out Spec (Reachability + **DOM**-No-Leak) + cross-DB-GO + phpunit cert-suite. **Kein Prod-Mutieren** (occ upgrade / info.xml-Bump / Cert-Mint / 429-curl-loop = deferred auf autorisierten Provisioning-Pass).
2. Danach macht der **execute-phase-Orchestrator**: gsd-verifier (Phase-Goal) → bei pass `phase complete` (HAND-EDIT, gsd-tools korrumpiert v5.0.0-Frontmatter!) → offer_next.

## KRITISCHE Carry-forwards (nicht vergessen)

- **Public-Routes brauchen `@PublicPage`-PHPDoc, NICHT das `#[PublicPage]`-Attribut** — Attribut warf live ein 401 logged-out (157-04 empirischer Fund, fix d05d593). Research sagte fälschlich „Attribut mandaten".
- **Synthetischer Cert `eb97720c` ist ABGERÄUMT** (DB-Row weg). Der DOM-Leak-Test braucht einen frischen Cert ODER eine lokale Render-Fixture mit echtem Namen — sonst **passt der stärkste VERIFY-03-Gate vacuously**. 157-05-Plan adressiert das; Integrity-Guard beachten (Name muss erst nachweislich IM Render-Pfad sein, bevor man Abwesenheit asserted).
- **info.xml bleibt 4.4.8** (Prod-Safety: Bump → needsDbUpgrade → `--php-only` bricht Live-App). Bump + `occ upgrade` + Migration-Apply (Version009200 dormant) reisen zusammen zum autorisierten Provisioning-Pass.
- **revoked_at-Migration ist dormant** (Code da, nicht live appliziert). Tombstone-Read liest die Spalte nur im withdrawn-Branch → un-applizierte Migration bricht den VALID-Pfad NICHT.
- **gsd-tools `state/roadmap`-Commands korrumpieren v5.0.0-Frontmatter** → STATE/ROADMAP/REQUIREMENTS IMMER hand-editieren, `milestone: v5.0.0` prüfen.
- **VERIFY-01..06 noch NICHT geflippt** — backend-complete, flippen beim 157-Close nach Live-Verify. Visual/credentialed Checks reiten auf dem Demo-Course-Pass (user option A, wie CERT-07/08/13).
- `.planning/phases/` ist gitignored → SUMMARYs/VERIFICATION mit `git add -f`.

## Offene Milestone-Carry-forwards (v5.0.0 Close)

- info.xml 4.4.8 → echter v5.0.0-Release-Bump (CHANGELOG + git tag) beim Milestone-Close.
- 156 Alt-Befund (Codex): `CertificateReportService::decodePayload` liest UNGEPRÜFTEN JWT-Payload → manipulierte DB-Row fälscht Compliance-Report. Mitigation: 157er `CertificateVerifyService` ist die reusable verify-before-decode-Primitive, die 156 später adoptieren kann. 156 NICHT wieder aufgemacht.
- Demo-Course „I am not an idiot test" (INBOX) — Träger für alle deferred visual/live Checks.

## Resume-Befehl

`/gsd:execute-phase 157` (findet 157-01..04 SUMMARYs → skippt sie → resumed bei 157-05 → Wave 4 → verifier → phase complete).

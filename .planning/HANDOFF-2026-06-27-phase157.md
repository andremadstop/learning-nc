# HANDOFF — Phase 157 Public-Verify ✅ CLOSED (+ v5.0.0 feature-complete)

**Stand:** 2026-06-28. Branch `feature/v5.0.0-certification`. (Ersetzt den mid-execution-Stand von 2026-06-27, der durch die Notbremse abgeschnitten wurde.)

> ⚠ **Korrektur zur Vorversion dieses Docs:** Die Zeile „157-05 ⛔ NICHT GESTARTET" war FALSCH — sie wurde in der Shutdown-Hektik aus einem älteren Stand geschrieben. 157-05 war zum Shutdown-Zeitpunkt bereits **code-complete + committet** (`639b2bc`). Die Artefakte (Commit + 21:33-SUMMARY) schlagen das Handoff-Wort. Diese Session hat das verifiziert und den Phase-Close nachgezogen.

## Wo wir stehen

**Phasen 154 + 155 + 156 + 157 ALLE COMPLETE → v5.0.0 ist FEATURE-COMPLETE.**

Phase 157 (Public-Verify): 5/5 Pläne, 4 Wellen, alle Gate-1 grün. gsd-verifier-Verdikt **CLOSE 6/6** (`157-VERIFICATION.md`, goal-backward gegen die Codebase, nicht gegen die SUMMARYs).

| Plan | Status | Kern |
|------|--------|------|
| 157-01 | ✅ | Version009200 `revoked_at` (DORMANT) + Entity + cross-DB |
| 157-02 | ✅ | CertificateVerifyService (sig+key-status+claim-binding+DSGVO-DTO), 10/10 PHPUnit |
| 157-03 | ✅ | Revoke-Write (owner-gated, idempotent) + „Widerrufen"-Button, 13/13 |
| 157-04 | ✅ | PublicVerifyController (@PublicPage PHPDoc) + verify.php (4 Banner) + i18n×5, 5/5 |
| 157-05 | ✅ | Playwright logged-out + Phase-Gate-Sweep. **Live re-run 2026-06-28: VERIFY-01 + VERIFY-06 GREEN**, VERIFY-03 gated-skip |

REQUIREMENTS VERIFY-01..06 geflippt (ehrliche deferred-Status). STATE/ROADMAP/REQUIREMENTS hand-editiert (gsd-tools korrumpiert v5.0.0-Frontmatter → NIE benutzen).

## NÄCHSTER SCHRITT — Entscheidung für Andre (kein Auto-Run!)

**Der gesamte Rest von v5.0.0 ist Prod-Mutation (Regel 15) und MUSS von dir autorisiert werden.** „mach direkt weiter" hat den Phase-Close autorisiert, NICHT Prod-Writes.

**Autorisierter Demo-Course-Provisioning-Pass** (User-Option A — Träger aller deferred Checks):
1. Demo-Kurs „I am not an idiot test" entwerfen (INBOX-Brainstorm) — oder zuerst Throwaway.
2. `occ upgrade` → wendet die dormant Version009200 `revoked_at` auf Live-PG16 an (⚠ zeigt Live-Usern kurz die Maintenance-Seite — Timing wählen).
3. info.xml 4.4.8 → bump (reist MIT `occ upgrade`).
4. Echten Cert auf dem Demo-Kurs minten (genuine Pass-Pipeline).
5. Dann die gated Checks ungaten/fahren:
   - **VERIFY-03** Playwright DOM-no-leak: `LIVE_VID` + beide Recipient-Konstanten in `app/tests/e2e/public-verify.spec.js` atomar setzen + psql-Präsenz bestätigen (sonst skip/vacuous).
   - **VERIFY-06** Live-429-Curl-Loop (⚠ der `#[AnonRateLimit]`-Pfad ist live-UNVERIFIZIERT auf einem Build, wo das Schwester-`#[PublicPage]`-Attribut still gedroppt wurde — **#1-Check**).
   - **VERIFY-05** credentialed Revoke-Smoke (instructor 200 / non-owner 404 / repeat-keeps-first-revoked_at; bruteforce-reset 172.21.0.1 zuerst).
   - valid/withdrawn/expired Banner + RTL-Arabisch visuell.

**Danach: v5.0.0-Milestone-Close-Release** — CHANGELOG + git tag + Store-Release (Codeberg). `/gsd:complete-milestone`.

## Offene INBOX-Entscheidungen (nicht-blockierend)
- **python3-cryptography im Live-Container devcloud-app** (155-03 installiert, non-persistent): (a) lassen / (b) revert / (c) ins Image backen. Claude-Vorschlag (c). Hängt am Provisioning-Pass (Independent-Verifier braucht es).
- **Demo-Kurs „I am not an idiot test"** — Brainstorm + Content-Quelle (NotebookLM-Reuse).

## Carry-forwards (nicht verlieren)
- Public-Routes: `@PublicPage`-**PHPDoc**, NICHT das `#[PublicPage]`-**Attribut** (warf live 401, d05d593).
- Revocation ist HEUTE NICHT live-funktional (Version009200 dormant) — erst nach `occ upgrade`.
- `.planning/phases/` ist gitignored → SUMMARYs/VERIFICATION mit `git add -f`.

## Resume
Frische Session: lies STATE.md + dieses Doc. Wenn Provisioning-Pass autorisiert → Demo-Kurs zuerst. Sonst → `/gsd:complete-milestone` vorbereiten (aber Live-Checks bleiben dann deferred).

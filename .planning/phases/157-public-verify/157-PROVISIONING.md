# Demo-Course Provisioning Pass — LIVE record (2026-06-28)

Authorized by Andre (user option A; "1-5 now"; mint method = seed throwaway student). Executed on live devcloud (relais). This is the v5.0.0 live-activation that flipped all 6 VERIFY requirements from deferred to live-proven.

## Live objects created (PERSIST as the demo)

| Object | ID / value | Notes |
|--------|-----------|-------|
| Migration | Version009200 `revoked_at` | applied live via `occ upgrade` (info.xml 4.4.8→4.4.9); column `revoked_at` bigint nullable present on PG16 |
| Pool DE | 165 | owner `andre`, 18 MCQ (questions 16632–16649) |
| Pool EN | 166 | owner `andre`, 18 MCQ (questions 16650–16667) |
| Course DE | 62 | `andre`-owned, title **"Internet-Mündigkeit 2026 — I am not an idiot test"**, cert 70%/365d, pool [165] |
| Course EN | 63 | `andre`-owned, title **"Internet Street-Smarts 2026 — I am not an idiot test"**, cert 70%/365d, pool [166] |
| Student (demo, has cert) | `demo-idiottest` | display "Demo Teilnehmer", enrolled 62+63 |
| Student (Andre's fresh test) | `andre-learner` | display "André (Test)", enrolled 62+63, Gate-2 pre-seeded, 0 certs (exam open for Andre) |
| Cert DE | id 4, vid **5362f079-8c6f-4f16-ab14-e3de3bb6df1f** | **VALID** showcase (re-minted with professional signed achievement.name) |
| Cert EN | id 5, vid **cc8f7e65-0571-43fe-9a95-1e224806c95b** | **VALID** (re-minted; both valid now for a clean bilingual showcase — withdrawn-state already proven + recorded) |

> **NOTE 2026-06-28 eve:** the original certs (id 2/3, vids 603d914c / 40689a85) were DELETED + re-minted (id 4/5) after the courses were retitled to the professional title (UX gate). The withdrawn-banner proof (VERIFY-05) ran on the old EN cert before re-mint. Playwright LIVE_VID now points at 5362f079.

Mint mechanism (per 155 pattern): seeded Gate 1 (completed exam session, course_id-scoped, 18/18) + Gate 2 (18 leitner_items box 5 = 100% mastery), then authenticated `GET /api/courses/{id}/pass-status` as the student → real `evaluate()` → `issueIfPassed()` signed + persisted the cert. NOTE: `getExamScore` filters by `course_id` (not pool_id) — the exam session MUST carry course_id.

## Gated 157 checks — ALL LIVE GREEN

- **VERIFY-01** logged-out reachable — Playwright `public-verify` project green.
- **VERIFY-02** valid banner #2f9a48 (DE) + withdrawn #e69900 (EN), course/issuer/dates render.
- **VERIFY-03** Playwright DOM whole-body no-leak GREEN against real cert 603d914c (LIVE_VID + recipient constants updated in spec; "Demo Teilnehmer"/demo-idiottest absent from entire HTML).
- **VERIFY-04** real cert renders valid AND `scripts/verify-credential.py` (independent Python Ed25519) → "signature is valid" against did.json's published key.
- **VERIFY-05** owner (andre, via temp app-password, deleted after) revoke → {revoked:true} HTTP 200; revoked_at set + UNCHANGED on repeat (idempotent); non-owner (demo-idiottest) → 404; EN verify page → #e69900 withdrawn tombstone (HTTP 200).
- **VERIFY-06** no-oracle Playwright green AND live 429: curl-loop on unknown branch → HTTP 429 after the window (also tripped the network brute-force limit "Zu viele Anfragen").

## Still optional / non-blocking (NOT done)
- CERT-07/08/13 visual eyeball (cert print / QR / LinkedIn share) — needs a browser session as the student; the DE cert is the vehicle.
- Expired banner (#6c757d) + RTL Arabic visual — would need a past-expiry cert / Arabic locale; minor.
- EN cert is withdrawn; if a VALID EN demo is wanted later, mint a fresh EN cert (the revoked one's active_idem_key is NULL).

## Teardown (if ever needed)
Delete certs 2+3, user `demo-idiottest` (occ user:delete — cascades sessions/leitner/members), courses 62+63, pools 165+166. Issuer key + did.json untouched. The Version009200 column stays (harmless). Temp app-passwords for `andre` already deleted.

## Scratch (relais, clean up): `~/learning-nc/{provision-idiottest.sh,idiot-pools-wrapper.json,verify-credential.py}`, `/tmp/idiottest-stu-pw.txt`.

## Release status
info.xml is **4.4.9** live (the migration-apply vehicle). The v5.0.0 release (info.xml→5.0.0 + CHANGELOG + git tag + Codeberg store) is the remaining user-gated step.

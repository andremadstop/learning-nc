# Demo-Course Provisioning Pass — LIVE record (2026-06-28)

Authorized by Andre (user option A; "1-5 now"; mint method = seed throwaway student). Executed on live devcloud (relais). This is the v5.0.0 live-activation that flipped all 6 VERIFY requirements from deferred to live-proven.

## Live objects created (PERSIST as the demo)

| Object | ID / value | Notes |
|--------|-----------|-------|
| Migration | Version009200 `revoked_at` | applied live via `occ upgrade` (info.xml 4.4.8→4.4.9); column `revoked_at` bigint nullable present on PG16 |
| Pool DE | 165 | owner `andre`, 18 MCQ (questions 16632–16649) |
| Pool EN | 166 | owner `andre`, 18 MCQ (questions 16650–16667) |
| Course DE | 62 | `andre`-owned, cert_enabled, pass 70%, validity 365d, required pool [165] |
| Course EN | 63 | `andre`-owned, cert_enabled, pass 70%, validity 365d, required pool [166] |
| Student | `demo-idiottest` | display "Demo Teilnehmer", enrolled in 62+63 (role student) |
| Cert DE | id 2, vid **603d914c-aaa0-4cf0-a49f-35ea9a219d87** | **VALID** showcase cert (key UI3V-D_j…), issued via genuine pass pipeline |
| Cert EN | id 3, vid **40689a85-cefa-4db4-9357-73b385ad17f6** | **WITHDRAWN** (revoked 2026-06-28 to prove VERIFY-05) — serves as the withdrawn-banner demo |

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

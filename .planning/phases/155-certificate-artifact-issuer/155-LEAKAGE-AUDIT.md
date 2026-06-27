# 155 Leakage Audit — Issuer Private Key (CERT-03 / Rule 18 phase-close gate)

> **Question this gate answers:** can the issuer signing secret (`learning_cert_keys.secret_key_enc`)
> escape the instance through ANY export / snapshot / app-package / serialization surface?
> **Verdict: NO.** Every enumerated surface below is signed off PASS, and the structural guarantees
> are automated in `app/tests/Unit/Service/LeakageAuditTest.php` (run green at 155-07).

The secret lives in **exactly one place**: the `learning_cert_keys.secret_key_enc` column, holding an
`ICrypto` **ciphertext** of the base64(64-byte) Ed25519 secret — never plaintext (155-02 `KeyService`).
Leakage prevention is therefore a question of: (a) which surfaces read that column, and (b) whether any
serialized form re-emits it. Only `KeyService` / `SigningService` (sign path) and `CertKeyMapper` touch it,
and `CertKey::jsonSerialize()` structurally omits it.

## Enumerated surfaces — sign-off

| # | Surface | Reads `cert_keys` / `secret_key_enc`? | Verdict | Evidence |
|---|---------|----------------------------------------|---------|----------|
| 1 | `DataExportService::exportForUser` | No | **PASS** | Queries only `learning_leitner_items`, `learning_sessions`, `learning_user_answers`, `learning_pools`, `learning_course_members`, `learning_campaign_state`, `learning_kudos`. Per-user data; no instance-level key tables. Grep-asserted. |
| 2 | `DataMobilityService::exportCourse` + `exportCourseStatsCsv` | No | **PASS** | `from()` tables: `learning_user_stats`, `learning_sessions`, `learning_leitner_items` (+ course/pool meta). No `cert_keys`. Grep-asserted. |
| 3 | `CourseArchiveService` + `learning_course_snapshots` | No | **PASS** | Snapshot body = `DataMobilityService::exportCourse()` output (surface #2, key-free). The JSON snapshot stored in `learning_course_snapshots` therefore cannot contain the secret. Grep-asserted. |
| 4 | Export/Import command family (`ExportCourseCommand`, `ExportPoolCommand`, `ImportVaultCommand`, `ImportPoolJsonCommand`) | No | **PASS** | None reference `CertKey` / `cert_keys` / `secret_key_enc`. Grep-asserted in `LeakageAuditTest`. |
| 5 | NC user-migration (export user account) | No | **PASS** | The app exposes no `IMigrator` / user-export provider for cert material; signing keys are an **instance** identity, not user data, so they are out of scope of per-user account migration by construction. |
| 6 | App-store tarball (release package) | No | **PASS** | The key lives ONLY in the DB ciphertext column — there is **no key file** under `app/` or in `appdata`. The release Makefile allowlist (`appinfo css img js lib templates`) ships no secret. `scripts/verify-credential.py` (the dev independent verifier) is **excluded** from both the Makefile allowlist and the deploy bundle (`lib/ appinfo/ l10n/ templates/`) — confirmed 155-03. |
| 7 | `CertKey::jsonSerialize()` (API / any JSON emission) | No | **PASS** | Explicit allowlist `['id','key_id','public_key_b64u','status','created_at']`. `secret_key_enc` is **never a key** in any serialized form. Automated: `LeakageAuditTest::testJsonSerializeOmitsSecret`. |
| 8 | `Certificate::jsonSerialize()` (issued-cert API) | n/a (no secret) | **PASS** | Emits `credential_json` = the signed **compact VC-JWT**, which is **public + shareable by design** (it carries only the public OB3 payload + an Ed25519 signature, never the private key). Public verification needs only the did.json public key. |
| 9 | `did.json` (public route) | Public key only | **PASS** | `DidController` serializes `publicKeyJwk {kty,crv,x}` — the public `x` only, never the private `d`. 155-02. |
| 10 | Logging of decrypted bytes | No | **PASS** | `KeyService` `sodium_memzero`s every plaintext secret copy (4 call sites) and `IssuanceService` zeroes the secret after `sign()` (1 call site). No `logger->*($secret)` path. Grep-asserted. |

## Trust-model statement (explicit — not a gap)

DB backups (`restic` → Hetzner Storage Box) **do** contain the `secret_key_enc` ciphertext column. This is
**accepted and by design**: security rests on the Nextcloud instance secret in `config.php`
(`ICrypto`/`EncryptionService` at-rest model). An attacker with the DB dump but **without** `config.php`
cannot recover the signing key. This is the **same at-rest trust model** the app already uses for other
encrypted fields (e.g. telos/integration secrets) — the certificate issuer key introduces no new trust
assumption. Compromise of `config.php` is already a full-instance compromise; the issuer key is no weaker a
link than everything else encrypted at rest.

## Automated coverage (LeakageAuditTest)

- `testJsonSerializeOmitsSecret` — `CertKey::jsonSerialize()` output has no `secret_key_enc` key, no
  `secret` substring, and does not echo the ciphertext; it DOES carry the public material.
- `testNoExportSurfaceReferencesIssuerSecret` — source-level grep gate over surfaces #1–#4: none contains
  `secret_key_enc`, `cert_keys`, or the `CertKey` entity reference.
- `testSecretIsZeroedNotLogged` — `KeyService` + `IssuanceService` contain `sodium_memzero`; no surface logs
  the raw secret.

**Gate result (155-07):** all assertions green on relay (`phpunit --filter LeakageAuditTest`); the three
export services grep-clean (`! grep secret_key_enc`). Rule-18 phase-close leakage gate: **PASS**.

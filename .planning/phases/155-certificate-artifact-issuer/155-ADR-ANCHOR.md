# 155 ADR Anchor — VC-JOSE-COSE Signing Contract (FROZEN)

> This is **not** a re-open of [ADR-001](../../decisions/ADR-001-signing-format.md) — that decision is
> **Accepted**. This anchor *freezes the concrete signing contract* that Phase 155 plans 02-07 must
> satisfy, and registers the three ADR follow-ups as named, verifiable downstream tasks.

## Frozen signing contract (plans 02-07 MUST satisfy)

- **Format:** VC-JWT (VC-JOSE-COSE) with **EdDSA** via PHP **`ext-sodium`** (`sodium_crypto_sign_detached`).
  **NO Composer dependencies.** No canonicalization.
- **JWS header (FROZEN):**

  ```json
  {"alg":"EdDSA","typ":"vc+jwt","cty":"vc","kid":"<verificationMethod.id>"}
  ```

  - `typ` is the literal token `vc+jwt`.
  - `kid` MUST equal the `verificationMethod.id` from `did.json`.
- **Payload = the OB3 credential object DIRECTLY.** **NO `vc` wrapper.** **NO `iss`/`sub`/`nbf`/`jti`
  registered-claim mirroring** — that is obsolete VC-JWT 1.1 behavior and is **forbidden** here.
- **Byte fidelity:** serialize the payload with `JSON_UNESCAPED_SLASHES` so the signed bytes are exactly
  the bytes that get base64url-encoded and emitted. Signing must operate on
  `base64url(header) . "." . base64url(payload)`.

### did:web form

- **Path-based DID:** `did:web:<host>:apps:learning`, resolving to `/apps/learning/did.json`.
- Served via a `#[PublicPage] #[NoCSRFRequired]` controller (NOT `.well-known`, which is fragile behind NPM).
- `verificationMethod` uses **`publicKeyJwk`** `{ "kty":"OKP", "crv":"Ed25519", "x":"<base64url>" }`.
- `kid` (in the JWT header) **== `verificationMethod.id`** (the did:web fragment).

## ADR-001 follow-ups → routed to downstream plans

| # | ADR-001 follow-up | Automated in | Mechanism |
|---|-------------------|--------------|-----------|
| 1 | Encoding correctness (`typ` header + claim-encoding rules per `https://www.w3.org/TR/vc-jose-cose/`) | **155-03** | `SigningService` byte/header assertions (signed bytes == emitted bytes; header == frozen contract) |
| 2 | Independent verifier (not just the in-app verify route) | **155-03** | Python `cryptography` Ed25519 verifier (node `jose` is NOT installed here) |
| 3 | `kid` alignment (`verificationMethod.id`) via `curl` against `did.json` | **155-07** | `test-api.sh` did.json assertion (kid == verificationMethod.id) |

## Data layer (this plan, 155-01)

Two tables, leakage-safe + cross-DB-portable (PostgreSQL 16 + MariaDB 11.4 utf8mb4):

- `learning_cert_keys` — issuer signing keys, **rotation foundation**. `secret_key_enc` holds an
  `ICrypto` ciphertext (never plaintext). `CertKey::jsonSerialize()` structurally omits `secret_key_enc`.
- `learning_certificates` — issued credentials. Each row references its signing `key_id`
  (→ `learning_cert_keys.key_id`), so key rotation never invalidates past certs.

No signing or key-generation code lives in 155-01 — only schema + storage + this anchor.

## ⚠ DOWNSTREAM WARNING — edit STATE.md / ROADMAP.md MANUALLY

The `gsd-tools` `state update-progress` / `state record-session` / `roadmap update-plan-progress`
commands **corrupt the v5.0.0 milestone frontmatter** — they overwrite `milestone: v5.0.0` → `milestone: v2.3`
and drop progress columns. **Every Phase 155 plan must edit `.planning/STATE.md` and `.planning/ROADMAP.md`
by hand** (with the Edit tool), never via those gsd-tools subcommands.

# Requirements: learning-nc — v5.0.0 Certification-as-a-Service

**Defined:** 2026-06-26
**Core Value:** Effektives Lernen mit Spaced Repetition in einer vertrauten Nextcloud-Umgebung — die App stellt verifizierbare Bestehens-Zertifikate als natives NC-Feature aus (kein externer Dienst).

## v1 Requirements

Requirements for v5.0.0. Each maps to a roadmap phase (154–157).

### Pass-Definition (PASS) — Phase 154

- [x] **PASS-01**: Instructor can enable certification for a course
- [x] **PASS-02**: Instructor can set a pass threshold (minimum score %) per course
- [x] **PASS-03**: Instructor can designate mandatory pools that must be mastered to pass
- [x] **PASS-04**: Instructor can set certificate validity duration (expires after N days; 0 = no expiry)
- [x] **PASS-05**: System evaluates pass as a binary from discrete assessment results, **excluding guessed answers** (`is_guessed`) — FSRS readiness is explicitly NOT the pass criterion
- [x] **PASS-06**: Student sees their pass status (passed / not yet) for a certifying course
- [x] **PASS-07**: A pass event is recorded immutably in the audit log when criteria are first met

### Certificate-Artifact & Issuer (CERT) — Phase 155

- [x] **CERT-01**: Admin can initialize the instance issuer identity via OCC command (`learning:cert:init-issuer` — Ed25519 keypair + did:web)
- [x] **CERT-02**: The instance publishes a resolvable did:web DID document (`did.json`) at a public route
- [x] **CERT-03**: Issuer private key is stored encrypted at rest (ICrypto) — never plaintext, never in export/snapshot/package
- [x] **CERT-04**: System supports multiple issuer keys over time with key rotation (`oc_learning_cert_keys`, key-id referenced in each credential) so rotation does not invalidate past certificates
- [x] **CERT-05**: On pass, the system **automatically** issues a signed Open Badges 3.0 / W3C VC credential
- [x] **CERT-06**: Each credential is self-contained — course, score, threshold, issue/expiry dates, issuer, verification-id embedded at signing time
- [ ] **CERT-07**: Student can view and print their certificate (window.print + print stylesheet) — *implemented + Vitest/build-proven; visual print render deferred to demo course (user option A, non-blocking)*
- [ ] **CERT-08**: Certificate displays a QR code linking to its public verification URL — *implemented + Vitest/build-proven; physical QR scan deferred to demo course (user option A, non-blocking)*
- [x] **CERT-09**: Student can download the credential as an Open Badges 3.0 JSON-LD file
- [x] **CERT-10**: Certificate content is multilingual — rendered in the viewer's language (DE/EN at minimum) via existing i18n
- [x] **CERT-11**: Certificate carries issuer branding (name + logo) pulled from the instance's NC theming settings (generic, zero per-operator config)
- [x] **CERT-12**: Student receives a Nextcloud notification when a certificate is issued
- [ ] **CERT-13**: Student can add the credential to LinkedIn via prefilled "Add to Profile" URL — *implemented + Vitest/build-proven; live redirect click deferred to demo course (user option A, non-blocking)*

### Compliance-Report (REPORT) — Phase 156

- [ ] **REPORT-01**: Instructor can view a compliance report per course (who passed, when, score, expiry, verification-id)
- [ ] **REPORT-02**: Instructor can filter the report by date range and expiry window
- [ ] **REPORT-03**: Instructor can export the compliance report as CSV (download)
- [ ] **REPORT-04**: Report exposes display name only — no plaintext email (DSGVO)

### Public-Verification (VERIFY) — Phase 157

- [ ] **VERIFY-01**: Anyone can verify a certificate via a public URL using its verification-id (no NC login)
- [ ] **VERIFY-02**: The verify page shows validity status, issuer, course title, and issue/expiry dates
- [ ] **VERIFY-03**: The verify response omits recipient personal data for unauthenticated callers (DSGVO)
- [ ] **VERIFY-04**: Verification cryptographically checks the signature against the issuer's published key AND the revocation/expiry status (signature alone ≠ currently valid)
- [ ] **VERIFY-05**: Instructor can revoke an issued certificate; verification then returns an explicit "withdrawn" status (tombstone, not 404)
- [ ] **VERIFY-06**: The verify route is rate-limited and validates input format (anti-enumeration / IDOR)

## v2 Requirements (deferred — v5.1)

### Differentiators (DIFF)

- **DIFF-01**: Expiry-approaching notification to student
- **DIFF-02**: Recipient email-hash ownership check on the credential
- **DIFF-03**: Bulk issuance / backfill for past passers
- **DIFF-04**: Verify-event audit trail
- **DIFF-05**: Bitstring Status List revocation (proportionate only at >10K credentials)

## Out of Scope

| Feature | Reason |
|---------|--------|
| Multi-tenant credentialing SaaS / org-issuer management | Mandant = the NC instance itself; tenancy via NC groups already exists. App feature, not a platform |
| eIDAS QEAA via Qualified Trust Service Provider | External contract/cost; overkill for app training certificate; did:web self-issuer suffices |
| EUDI / Apple / Google Wallet, OpenID4VCI | Requires issuance infrastructure absent in a self-hosted NC app — defer to v6+ |
| Europass EDC | Separate institutional issuing pipeline, not an OB3 format variant — v6+ |
| 1EdTech OB 3.0 conformance certification | Requires eddsa-rdfc-2022 (no PHP lib); in-app verify works without it; ADR-documented tradeoff |
| External verify-portal for non-NC supervisors | In-app public verify route covers the need for v5.0.0; standalone portal possibly later |
| PHP server-side PDF library | Browser-print (window.print) is the deliberate PDF strategy — NC resource constraint |
| FSRS readiness as pass criterion | Anti-feature — produces a certificate whose truth value silently decays |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| PASS-01 | 154 | Complete |
| PASS-02 | 154 | Complete |
| PASS-03 | 154 | Complete |
| PASS-04 | 154 | Complete |
| PASS-05 | 154 | Complete |
| PASS-06 | 154 | Complete |
| PASS-07 | 154 | Complete |
| CERT-01 | 155 | Complete |
| CERT-02 | 155 | Complete |
| CERT-03 | 155 | Complete |
| CERT-04 | 155 | Complete |
| CERT-05 | 155 | Complete |
| CERT-06 | 155 | Complete |
| CERT-07 | 155 | Implemented (visual verify deferred) |
| CERT-08 | 155 | Implemented (visual verify deferred) |
| CERT-09 | 155 | Complete |
| CERT-10 | 155 | Complete |
| CERT-11 | 155 | Complete |
| CERT-12 | 155 | Complete |
| CERT-13 | 155 | Implemented (visual verify deferred) |
| REPORT-01 | 156 | Pending |
| REPORT-02 | 156 | Pending |
| REPORT-03 | 156 | Pending |
| REPORT-04 | 156 | Pending |
| VERIFY-01 | 157 | Pending |
| VERIFY-02 | 157 | Pending |
| VERIFY-03 | 157 | Pending |
| VERIFY-04 | 157 | Pending |
| VERIFY-05 | 157 | Pending |
| VERIFY-06 | 157 | Pending |

**Coverage:** 30/30 v1 requirements mapped (PASS 7 → Phase 154, CERT 13 → Phase 155, REPORT 4 → Phase 156, VERIFY 6 → Phase 157)

---
*Requirements defined: 2026-06-26*
*Last updated: 2026-06-27 — Phase 155 close-out: CERT-01..06/09..12 Complete (live-verified); CERT-07/08/13 implemented, visual verify deferred to demo course (user option A)*

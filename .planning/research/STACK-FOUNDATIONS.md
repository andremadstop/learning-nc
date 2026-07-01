# Stack Foundations: Three RED Decisions for v5.2.0 Pflichtschulung

**Project:** learning-nc
**Researched:** 2026-07-01
**Confidence:** HIGH (direct codebase read — all named classes verified on disk)
**Scope:** Supplements existing STACK.md / ARCHITECTURE.md. Does NOT re-cover video-gating,
team-RBAC-reports, re-cert reminders, or username-politur — those are in ARCHITECTURE.md.
This pass covers ONLY the three foundations that are expensive to retrofit.

---

## RED-1: Tamper-Evident Audit Trail

### Recommendation: Three Layers (Hash-Chain + Ed25519 Checkpoints + External Anchor)

These are **not alternatives** — each defeats a different adversary. All three must be designed
now; the first two must be built now.

| Layer | Adversary Defeated | Build When |
|-------|-------------------|------------|
| 1. Per-event hash chain (write path) | SQL UPDATE/DELETE, IDOR, support-user DB edit | Now — v5.2.0 |
| 2. Periodic Ed25519 signed checkpoint | DB-only adversary: backup tampering, DBA, SQL injection | Now — v5.2.0 |
| 3. External anchor (publish to Forgejo) | Instance admin fabricating records after the fact (holds the signing key AND the DB) | Design now, deploy fast-follow |

**Why Layer 3 is the critical one:** AWO runs its own Nextcloud. Its admin holds the signing key
(`KeyService`) AND controls the DB. They could re-derive a valid hash chain over fabricated rows
and re-sign a checkpoint on-box. Only an external, independently-timestamped artifact closes
that threat. Layer 3 must be *designed* now so anchoring is a single `PUT` call — not a schema
rebuild. It can be deployed as a fast-follow once the key token is provisioned.

---

### Layer 1: Per-Event Hash Chain (Write Path)

#### Current State of AuditService

`AuditService::logEvent()` (Version001900, 2026-03-11) inserts four columns: `event_key`,
`user_id`, `context_json`, `created_at`. No sequence, no hash. Any row can be UPDATEd or
DELETEd without leaving a trace. The existing method wraps all writes in `try/catch` and
silently swallows failures so audit logging never breaks app flow. **A hash chain changes this
contract for compliance events** — see Chain Gap Trap below.

#### Schema Additions (PG16 + MariaDB utf8mb4 safe)

ALTER `learning_audit_events` via a new migration (e.g., Version009300):

```php
if ($schema->hasTable('learning_audit_events')) {
    $table = $schema->getTable('learning_audit_events');
    if (!$table->hasColumn('prev_hash')) {
        $table->addColumn('prev_hash', Types::STRING, ['notnull' => false, 'length' => 64]);
    }
    if (!$table->hasColumn('chain_hash')) {
        $table->addColumn('chain_hash', Types::STRING, ['notnull' => false, 'length' => 64]);
    }
    if (!$table->hasIndex('learn_audit_chain_idx')) {
        $table->addIndex(['chain_hash'], 'learn_audit_chain_idx');  // 22 chars OK
    }
}
```

Both columns are **nullable** so existing rows and new non-compliance events (using `logEvent()`)
remain valid without modification. Compliance events use `logComplianceEvent()` which populates
both. The autoincrement `id` column (already present) serves as the sequence number — no
separate `seq_num` column is needed.

#### AuditService Extension

**No `SELECT … FOR UPDATE` is available in NC OCP IQueryBuilder** (confirmed — the interface
does not expose row locking). Serialization is handled instead by the upstream caller's
idempotency guards (compliance events are rare and already partially serialized by
`active_idem_key`). A concurrent double-write that produces a fork (two rows with the same
`prev_hash`) is **detectable** by the verifier as a branch, which is distinct from tampering.
Document this as the concurrency model.

```php
/**
 * Log a compliance-critical audit event with hash-chain integrity.
 *
 * DOES NOT swallow exceptions — a failed compliance write must propagate (see Chain Gap Trap).
 *
 * chain_hash = sha256( canonical_json(event fields) || prev_hash )
 * canonical_json = JSON_UNESCAPED_UNICODE|SLASHES of {event_key, user_id, course_id, created_at}
 *
 * PII-minimal: only pseudonymous user_id, event_key, course_id, created_at are hashed.
 * Free-form context_json is stored but excluded from hash — it may contain display_name
 * or PII that would impair Art. 17 erasure without affecting integrity provability.
 *
 * Concurrency: reads prev_hash from MAX(id) compliance row inside a transaction.
 * Concurrent writes produce a detectable fork (two rows with same prev_hash), not a gap.
 * In practice this is rare: compliance events are guarded by upstream idempotency locks.
 */
public function logComplianceEvent(string $eventKey, string $userId, array $context = []): void {
    $this->db->beginTransaction();
    try {
        // Read head of chain — last compliance event by insert order
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'chain_hash')
            ->from('learning_audit_events')
            ->where($qb->expr()->isNotNull('chain_hash'))
            ->orderBy('id', 'DESC')
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $last = $result->fetch();
        $result->closeCursor();

        $prevHash = $last !== false ? $last['chain_hash'] : str_repeat('0', 64);
        $ts = time();

        // Canonical payload — only these PII-minimal fields enter the hash
        $canonicalInput = json_encode([
            'event_key'  => $eventKey,
            'user_id'    => $userId,
            'course_id'  => $context['course_id'] ?? null,
            'created_at' => $ts,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $chainHash = hash('sha256', $canonicalInput . $prevHash);

        $qb2 = $this->db->getQueryBuilder();
        $qb2->insert('learning_audit_events')
            ->values([
                'event_key'    => $qb2->createNamedParameter($eventKey),
                'user_id'      => $qb2->createNamedParameter($userId),
                'context_json' => $qb2->createNamedParameter(json_encode($context, JSON_UNESCAPED_UNICODE)),
                'created_at'   => $qb2->createNamedParameter($ts),
                'prev_hash'    => $qb2->createNamedParameter($prevHash),
                'chain_hash'   => $qb2->createNamedParameter($chainHash),
            ]);
        $qb2->executeStatement();

        $this->db->commit();
    } catch (\Throwable $e) {
        $this->db->rollBack();
        throw $e;  // MUST propagate — caller must handle compliance write failure
    }
}
```

**Callers that must switch to `logComplianceEvent()`:**
- `PassCriteriaService::emitPassEventIfFirst()` — `course.passed` event
- `IssuanceService` — `cert.issued` event (grep for existing `auditService->logEvent` calls there)
- `CertificateVerifyService` (v5.0.0) — `cert.revoked` event

Non-compliance events (moderation, schwarm votes, AI consent) keep `logEvent()` with its
swallow-and-continue behavior unchanged.

---

### Layer 2: Periodic Ed25519 Signed Checkpoints

#### New Table: `learning_audit_checkpoints`

```php
if (!$schema->hasTable('learning_audit_checkpoints')) {
    $table = $schema->createTable('learning_audit_checkpoints');
    $table->addColumn('id',             Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
    $table->addColumn('from_event_id',  Types::BIGINT, ['notnull' => true]);
    $table->addColumn('to_event_id',    Types::BIGINT, ['notnull' => true]);
    $table->addColumn('head_hash',      Types::STRING, ['notnull' => true, 'length' => 64]);
    $table->addColumn('event_count',    Types::BIGINT, ['notnull' => true]);
    $table->addColumn('signed_at',      Types::BIGINT, ['notnull' => true]);
    $table->addColumn('key_id',         Types::STRING, ['notnull' => true, 'length' => 64]);
    $table->addColumn('signature_b64u', Types::TEXT,   ['notnull' => true]);
    // Populated after Layer 3 publishes; NULL until anchored
    $table->addColumn('anchor_url',     Types::STRING, ['notnull' => false, 'length' => 512]);
    $table->setPrimaryKey(['id']);
    $table->addIndex(['to_event_id'], 'learn_aud_chk_to_idx'); // 22 chars OK
}
```

#### AuditCheckpointService

**Do NOT reuse `SigningService::sign()`** — its header contract is frozen to `typ:vc+jwt`
(155-ADR-ANCHOR). An audit checkpoint is a different payload type. Use `sodium_crypto_sign_detached`
directly via `KeyService` (same ext-sodium, zero new deps):

```php
class AuditCheckpointService {
    public function __construct(
        private readonly IDBConnection $db,
        private readonly KeyService    $keyService,
        private readonly LoggerInterface $logger,
    ) {}

    public function createCheckpoint(): void {
        // 1. Find last checkpoint's to_event_id (0 if none)
        // 2. Query compliance events (chain_hash IS NOT NULL) since last checkpoint
        //    COUNT, MAX(id) as toEventId, MIN(id) as fromEventId, last row's chain_hash as headHash
        // 3. Build checkpoint payload
        $payload = json_encode([
            'typ'        => 'audit_checkpoint',  // distinct from vc+jwt — must NOT reuse SigningService
            'issuer'     => $this->keyService->hostDid(),
            'from_event_id' => $fromEventId,
            'to_event_id'   => $toEventId,
            'head_hash'     => $headHash,
            'event_count'   => $count,
            'signed_at'     => time(),
        ], JSON_UNESCAPED_SLASHES);

        // KeyService::getActiveSigningMaterial() returns ['key' => CertKey, 'secret' => string]
        // (verified from KeyService.php line 184 — keyed array, not positional tuple)
        $material = $this->keyService->getActiveSigningMaterial();
        $key = $material['key'];
        $secretKeyRaw = $material['secret'];

        $signature = sodium_crypto_sign_detached($payload, $secretKeyRaw);
        sodium_memzero($secretKeyRaw);

        $signatureB64u = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        // Insert checkpoint row with null anchor_url — Layer 3 updates it
        // ...
    }
}
```

**TimedJob:** `AuditCheckpointJob` — weekly (604800s interval). Registered in `Application.php`
alongside `SendRemindersJob`. `run()` calls `AuditCheckpointService::createCheckpoint()`.

**Independent verification:** decode payload JSON, fetch public key from `/did.json` via
`key_id`, call `sodium_crypto_sign_verify_detached(base64_decode($signatureB64u), $payload, $pubKey)`.
No app trust required.

---

### Layer 3: External Anchor (Design Now, Deploy Fast-Follow)

The checkpoint artifact is self-contained (JSON + Ed25519 signature). Anchoring to UG Forgejo
is a single HTTP PUT to the contents API:

```
PUT https://git.andrestiebitz.de/api/v1/repos/andre/audit-anchors/contents/checkpoints/{date}.json
Authorization: token {FORGEJO_AUDIT_TOKEN}
Body: { "message": "audit checkpoint {date}", "content": base64_encode(checkpoint_json) }
```

`AuditCheckpointService::createCheckpoint()` stores `anchor_url` (NULL until anchored). Enabling
anchoring is one config flag + storing the token in `.env`. Forgejo commits create an immutable,
externally-timestamped record that no on-box admin can alter retroactively.

**Phase flag:** External anchor is a distinct sub-task — build Layer 1+2 first. Anchoring is
then "add ~20 lines + provision token."

---

### DSGVO Stance

**PII-minimal canonical hash:** The hash input contains only `event_key`, `user_id`
(pseudonymous NC uid), `course_id`, `created_at`. Display names, email addresses, and other
PII are never hashed — they live in `context_json` which is stored but chain-excluded.

**Art. 17 erasure vs. Art. 17(3)(b) Nachweispflicht:** An Art. 17 erasure request for a
compliance event row collides with Art. 17(3)(b) — processing necessary to comply with a legal
obligation (Nachweispflicht, e.g., ArbSchG, AGG §12). Document this retention basis. After the
legal retention period expires, the entire compliance record can be purged (the row, its hash,
and the checkpoint covering that range), which is a clean and complete deletion.

---

### Chain Gap Trap (Critical Behavioral Change)

The current `logEvent()` swallows exceptions: audit never breaks app flow. A hash chain turns a
**silently-dropped compliance write into a detectable chain gap** — which looks identical to
tampering to the verifier. Two compliance events with an ID gap (no `chain_hash` row between
them) raise an alarm even if both events are legitimate.

**Resolution:** `logComplianceEvent()` does NOT swallow — it propagates. Callers must handle
thrown exceptions (surface as 500 / retry). The existing `logEvent()` remains unchanged for
non-compliance events. **This is a behavioral breaking change for the three callers listed
above.** Spec it explicitly in the v5.2.0 audit-hardening sub-task.

---

### Verifier Tool

An `occ learning:audit:verify` command must ship with the hash chain. A tamper-evident log
without a verifier is theater.

The command:
1. Walks all events with `chain_hash IS NOT NULL` in `id` order.
2. For each, recomputes `chain_hash` from the stored PII-minimal fields + `prev_hash`.
3. Flags any mismatch, any fork (two rows sharing a `prev_hash`), or any unexpected ID gap.
4. For each checkpoint, fetches the public key by `key_id` from `learning_cert_keys` and
   verifies `sodium_crypto_sign_verify_detached`.
5. Outputs: total events, verified checkpoints, first anomaly (if any), anchor URLs.

---

### Retrofit Cost

**Code retrofit is cheap.** Every compliance write funnels through `AuditService` (confirmed:
PassCriteriaService, TrainingService, GeminiService, UserDeletedListener, SettingsController
all call `logEvent`). The chain logic lives in one new method + one migration.

**Historical provability retrofit is impossible.** Chain from retrofit-date forward and every
pre-existing event can only be hashed over its current (possibly-already-altered) state. AWO's
audit requirement is "prove person X completed course Y on date Z." If the chain starts after
v5.2.0 deploy, all prior events are permanently unprovable. Build it in the write path on
v5.2.0 day one or accept that no pre-v5.2.0 history can ever be integrity-proven.

---

## RED-2: Cert Verifiability + PGP/WKD Sovereignty Lever

### Recommendation: Defer WKD per-cert signing to v5.3+

**Verdict:** did:web + Ed25519 + public verify (v5.0.0) is sufficient for v5.2.0 compliance
use-cases. Adding OpenPGP/WKD to the per-cert signing path is not warranted.

### Three Decisive Reasons to Defer

**1. YubiKey physical touch is incompatible with automated issuance (killer constraint).**
Certs mint unattended inside `IssuanceService::issueIfPassed()` — a code path with no human
in the loop. A YubiKey requires a physical touch/PIN per signing operation. You cannot place
that in `issueIfPassed()` without either removing the touch requirement (defeating its security
value) or switching to batch-manual signing (defeating automation). This alone eliminates
per-cert WKD as a v5.2.0 option.

**2. No core-PHP OpenPGP — would require new deps or shelling to gpg.**
Ed25519 via ext-sodium is a PHP core extension with zero deps. OpenPGP has no core-PHP
implementation. Options: (a) composer lib — violates "prefer no new deps"; (b) shell to
`gpg` — fragile, adds process-management complexity, reintroduces the YubiKey touch problem.

**3. did:web + Ed25519 already satisfies "auditor verifies without trusting the app."**
v5.0.0 ships a public verify portal + independent Python verifier. The cert embeds the issuer
DID, `did.json` publishes the public key, and signatures are verifiable offline. WKD adds a
different trust anchor (email-based key discovery), not additional cryptographic strength for
the compliance use-case.

### WKD's Natural Insertion Point (Defer to v5.3+)

**Per-cert WKD is architecturally wrong for the VC-JWT format.** `SigningService` emits a
compact JWS (header.payload.signature). A JWS has exactly one signature — there is no
`proof` array as in JSON-LD Data Integrity. Adding a second WKD/PGP proof to an issued cert
would require changing the signed payload, which invalidates the existing Ed25519 signature.
There is no "append a second proof" without re-issuance.

WKD/PGP's correct insertion point is the **audit checkpoint** (RED-1 Layer 3). The weekly
checkpoint is a batch artifact signed at a scheduled moment — exactly the human-touch,
YubiKey-compatible operation. A checkpoint counter-signed with the UG's WKD key and published
to Forgejo is:
- The right use of a hardware-touch key (manual, deliberate, low-frequency)
- The "walk the talk" sovereignty differentiator (same key as UG email signing)
- The external anchor that closes the RED-1 fabrication threat

This synthesis means RED-2's business goal (visible sovereignty, independent verifiability)
is achieved via RED-1 Layer 3, not a separate per-cert signing path.

**v5.3 task:** "Add WKD countersignature to `AuditCheckpointService::createCheckpoint()`" —
one additional `gpg --sign` call on the checkpoint JSON, YubiKey-touch compatible, stored
alongside the Ed25519 signature. Zero cert schema changes required.

### Retrofit Cost

Negligible if WKD is deferred to the checkpoint rather than per-cert. The per-cert VC-JWT
format cannot accommodate a second proof without re-minting all existing certs. By routing WKD
to the checkpoint instead, the cert format stays frozen (correct — ADR-ANCHOR is FROZEN) and
WKD docks onto a purpose-built, human-touch artifact. No data model retrofit risk.

---

## RED-3: "Assignment" as a First-Class Object

### Recommendation: Two-Table Model (Assignment + Oversight)

An **assignment** is an obligation — "who must complete what by when." An **oversight** is a
permission — "who may view which group's report." Different relations, different cardinalities,
different lifecycles. Separate tables are cleaner than a `kind` discriminator that leaves half
the columns NULL depending on the row type.

**Supersession statement:** This design SUPERSEDES ARCHITECTURE.md's proposed
`learning_team_leads` table. `learning_oversight` replaces it with corrected column types and
a clearer name. The course-level `recert_enabled` and `recert_interval_days` columns proposed
in ARCHITECTURE.md remain as course defaults. The Assignment model adds per-assignment
`due_date` and `recert_interval_days` override to drive individual deadlines. The roadmapper
must NOT build both `learning_team_leads` AND `learning_assignments` — only the pair below.

---

### Schema: `learning_assignments` (NEW table)

```php
$table = $schema->createTable('learning_assignments');
$table->addColumn('id',                   Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
$table->addColumn('course_id',            Types::BIGINT, ['notnull' => true]);
// Polymorphic subject: 'user' | 'group'
$table->addColumn('subject_type',         Types::STRING, ['notnull' => true, 'length' => 16]);
// user_id OR nc_group gid. NC's oc_groups.gid is VARCHAR(64); confirm AWO's LDAP gids fit.
$table->addColumn('subject_id',           Types::STRING, ['notnull' => true, 'length' => 64]);
$table->addColumn('assigned_by',          Types::STRING, ['notnull' => false, 'length' => 64]);
$table->addColumn('assigned_at',          Types::BIGINT, ['notnull' => true, 'default' => 0]);
$table->addColumn('due_date',             Types::BIGINT, ['notnull' => false]);
// Per-assignment recert override. NULL = use course.recert_interval_days default
$table->addColumn('recert_interval_days', Types::BIGINT, ['notnull' => false]);
// Persisted base status. 'overdue'/'expired' are DERIVED at read (see Status Strategy below)
$table->addColumn('status',               Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'assigned']);
// Nullable unique slot — mirrors learning_certificates.active_idem_key (Version009100).
// Identifies the CURRENT active period: "<course_id>:<subject_type>:<subject_id>".
// NULL when period closes — frees the UNIQUE slot for re-assignment/re-cert.
$table->addColumn('active_period_key',    Types::STRING, ['notnull' => false, 'length' => 128, 'default' => null]);
$table->setPrimaryKey(['id']);
// PLAIN index — not unique. Multiple period rows per subject+course are allowed (re-cert history).
// active_period_key UNIQUE is what enforces "at most one ACTIVE period per subject+course".
$table->addIndex(['course_id', 'subject_type', 'subject_id'], 'learn_asn_crs_subj_idx'); // 26 chars OK
$table->addIndex(['subject_type', 'subject_id'],              'learn_asn_subj_idx');      // 19 chars OK
$table->addIndex(['course_id'],                               'learn_asn_course_idx');     // 20 chars OK
$table->addUniqueIndex(['active_period_key'],                 'learn_asn_period_uq');      // 20 chars OK
$table->addIndex(['due_date'],                                'learn_asn_due_idx');        // 18 chars OK
```

**Why `(course_id, subject_type, subject_id)` is a PLAIN INDEX, not UNIQUE:**
Re-cert creates a new assignment row for each period (same subject+course, new `active_period_key`).
A unique composite would reject the second period row. The `active_period_key` UNIQUE is the
correct guard: it enforces "at most one active period" while permitting unlimited historical rows.
This is identical to how `learning_certificates.active_idem_key` (Version009100) works — non-unique
`user_id+course_id` index + nullable-unique `active_idem_key`. The model reuses a proven
pattern from the same codebase.

**Status field — persist base, derive overdue/expired at read:**
Persist: `assigned` | `in_progress` | `passed`.
Derive at read: `overdue` when `due_date < now AND status != 'passed'`; `expired` when
`active_period_key IS NULL AND status = 'passed'` (old period, closed by re-cert cycle).
Derivation is race-free (no TimedJob needed to flip status). Reporting queries add the date
condition inline: `WHERE status IN ('assigned','in_progress') AND due_date < :now`. This is
correct for PG16 + MariaDB at <10k users; materialization adds write complexity without
meaningful read gain at this scale.

---

### Schema: `learning_oversight` (NEW — supersedes ARCHITECTURE.md's `learning_team_leads`)

```php
$table = $schema->createTable('learning_oversight');
$table->addColumn('id',             Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
$table->addColumn('course_id',      Types::BIGINT, ['notnull' => true]);
$table->addColumn('lead_user_id',   Types::STRING, ['notnull' => true, 'length' => 64]);
// NC group gid — VARCHAR(64) in oc_groups. Previous migration used 255 (oversized); 64 corrected here.
$table->addColumn('scope_group_id', Types::STRING, ['notnull' => true, 'length' => 64]);
$table->addColumn('created_at',     Types::BIGINT, ['notnull' => true, 'default' => 0]);
$table->setPrimaryKey(['id']);
// "learn_ov_crs_usr_grp_uq" = 24 chars OK
$table->addUniqueIndex(['course_id', 'lead_user_id', 'scope_group_id'], 'learn_ov_crs_usr_grp_uq');
$table->addIndex(['course_id'],    'learn_ov_course_idx'); // 20 chars OK
$table->addIndex(['lead_user_id'], 'learn_ov_lead_idx');   // 19 chars OK
```

**Note on `scope_group_id` length:** Existing migrations used 255 (e.g., Version000500). NC's
`oc_groups.gid` is VARCHAR(64). 64 is correct; 255 was oversized. Verify AWO's LDAP group CNs
fit within 64 chars before deploy.

**Why oversight and assignment are separate tables:** oversight rows have no `due_date`, no
`recert_interval_days`, no `active_period_key`. A `kind` discriminator merging both would make
40–50% of columns always NULL depending on the row type. The responsibility split is also clean:
`learning_assignments` drives the obligation lifecycle; `learning_oversight` grants view scopes.

---

### LDAP Transparency via IGroupManager (Confirmed HIGH confidence)

**Claim:** NC's `user_ldap` / SSO makes LDAP/AD users + groups appear transparently through
`IGroupManager` / `IUserManager` — the app needs no LDAP-specific code.

**Evidence from existing codebase:** `RoleService.php` already calls
`$this->groupManager->isAdmin($userId)` and `$this->groupManager->isInGroup($userId, $group)`
with no LDAP conditionals. These calls work for LDAP-sourced users today.

**Evidence from NC source:** `user_ldap/lib/Group_LDAP.php` implements `GroupInterface` and
registers as a backend provider behind `IGroupManager`. All `IGroupManager` calls —
`isInGroup()`, `getGroup()`, `getGroup()->getUsers()` — transparently delegate to the LDAP
backend or local backend depending on user origin. The app sees one uniform API regardless
of whether users come from LDAP/AD, SAML/SSO, or local NC accounts.
Source: [github.com/nextcloud/server — user_ldap/lib/Group_LDAP.php](https://github.com/nextcloud/server/blob/master/apps/user_ldap/lib/Group_LDAP.php)

**Consequence for RED-3:** A group assignment (`subject_type='group'`, `subject_id='abteilung-x'`)
automatically covers LDAP/AD group members. `IGroupManager::getGroup('abteilung-x')->getUsers()`
returns all members regardless of auth backend. No SCIM sync, no LDAP SDK, no extra config.

---

### How the Assignment Model Resolves ARCHITECTURE.md Crux 3

ARCHITECTURE.md left the re-cert `emitPassEventIfFirst` guard as an open design question:
> "The roadmapper must pick one and spec it explicitly before implementation."

The Assignment model resolves this — but the guard must NOT route issuance through the
assignment table. **Self-enrolled learners (the core v1–v5 user base) have no assignment row.**
Gating cert issuance on assignment presence would break cert issuance for all un-assigned
learners.

**Correct design: issuance guard stays in IssuanceService; assignment is a status layer.**

1. `IssuanceService::issueIfPassed()` keeps `active_idem_key` as the issuance-idempotency
   truth (ARCHITECTURE.md option 2 — unchanged). Un-assigned learners get certs as before.
2. After a cert is issued, a **listener or inline call** checks for a matching active assignment
   (`WHERE course_id=:c AND subject_type='user' AND subject_id=:u AND active_period_key IS NOT NULL`)
   and advances its `status` to `'passed'`. If no assignment row exists, nothing happens.
3. `emitPassEventIfFirst()` continues to use the audit event log for its dedup guard — OR, if
   the roadmapper picks option 2 from ARCHITECTURE.md (rely solely on `active_idem_key`),
   the audit event guard is removed. Either is valid; neither needs the assignment table.

**Where the assignment resolves Crux 3:** The re-cert period close step (`RecertPeriodCloseJob`)
sets `active_period_key = NULL` on the old assignment row (same mechanic as cert `active_idem_key`
nullification), then inserts a NEW assignment row with a fresh `active_period_key`. After the
period close, `issueIfPassed()` finds no non-revoked cert (old cert was revoked) and issues a
fresh one. The new assignment row's status advances to `'passed'` when that happens. The period
boundary is now **explicit in the assignment row** — no invented `cert.period_closed` audit
events needed. The roadmapper still must choose between ARCHITECTURE.md's two `emitPassEventIfFirst`
options; the assignment model does not change which one is picked, but it eliminates the need
to invent a new event type.

---

### Deadlines and Reminders (Email-less-safe)

**Trigger:** Extend `SendRemindersJob` (hourly TimedJob, already exists) with a
`sendAssignmentDueReminders()` method alongside `sendExamReminders()` and
`sendCertExpiryReminders()` (ARCHITECTURE.md).

**Query pattern (user assignments):**
```sql
SELECT a.subject_id AS user_id, a.course_id, a.due_date
FROM learning_assignments a
WHERE a.subject_type = 'user'
  AND a.status IN ('assigned', 'in_progress')
  AND a.active_period_key IS NOT NULL
  AND a.due_date IS NOT NULL
  AND a.due_date BETWEEN :now AND :now_plus_30d
```

Group assignments (`subject_type='group'`) are expanded via
`IGroupManager::getGroup($subjectId)->getUsers()` before sending individual notifications.

**INotificationManager (not email):** All reminders go through NC's built-in
`INotificationManager`. This is the email-less-safe channel — which is the explicit goal of
v5.2.0 Block 4 (Username-Politur: users without email accounts). `ReminderService` already
uses `INotificationManager` for exam reminders; the assignment reminder follows the same
pattern. **Do not add SMTP as a first-class reminder channel** — v5.2.0 explicitly targets
environments where users have no email.

**Dedup guard:** `IConfig::setUserValue` marker with key
`learn_asn_reminder_{assignmentId}_{due_date_epoch}` — same pattern as
`EXAM_REMINDER_KEY_PREFIX` in existing `ReminderService`. Prevents duplicate notifications
across hourly job runs.

---

### Retrofit Cost

**Structurally impossible to retrofit without a new table.** Current `learning_course_members`
(enrolled / not enrolled, role: student/instructor) has no `due_date`, no assignment originator,
no period lifecycle. A due_date column on `learning_course_members` would conflate enrollment
(a persistent relationship) with assignment (a time-bounded obligation with status lifecycle).
Re-cert requires multiple period rows per subject+course — incompatible with the single-row-
per-enrollment model.

Retrofitting later means: (a) building `learning_assignments` anyway, (b) migrating "enrollment
without deadline" as implicit open-ended assignments, (c) redesigning the re-cert guard around
assignment periods instead of course columns. The model is simpler to build now than to
retroactively add deadline semantics to a membership table that was never designed for them.

---

## Phase Flags for Roadmapper

| Sub-task | Recommended Phase | Notes |
|----------|------------------|-------|
| RED-1: hash-chain migration + `logComplianceEvent()` + switch 3 callers | v5.2.0 Phase 1 (audit hardening sub-task) | Must precede all compliance feature phases — chain starts from first v5.2.0 event |
| RED-1: `AuditCheckpointJob` + `learning_audit_checkpoints` + `occ verify` | v5.2.0 Phase 1–2 | Can trail by one phase; checkpoint without events is harmless |
| RED-1: External anchor (Forgejo publish) | Fast-follow to Phase 1 | Config flag + token; zero schema changes |
| RED-2: proof-array — DO NOT BUILD | Not applicable | VC-JWT has no proof array; WKD docks on checkpoint, not per-cert |
| RED-3: `learning_assignments` + `learning_oversight` migrations | v5.2.0 Phase 1 | Foundation for team-lead reports and re-cert — must be live before those phases |
| RED-3: `AssignmentService` + bulk-assign endpoint + reminder extension | v5.2.0 Phase 2 | After schema is live |
| RED-2 + RED-1: WKD countersignature on audit checkpoint | v5.3+ | Separate milestone — YubiKey-touch + Forgejo publish; zero cert schema changes |

---

## Confidence Assessment

| Area | Confidence | Reason |
|------|------------|--------|
| RED-1 hash-chain design | HIGH | AuditService.php + migrations read directly; sha256 via core `hash()`, no deps |
| RED-1 chain serialization (no `forUpdate`) | MEDIUM | IQueryBuilder confirmed to lack `forUpdate()` (GitHub); fork-detectable approach is correct but untested under load |
| RED-1 Layer 3 (Forgejo API) | MEDIUM | Forgejo contents API is stable/standard; token + path format not yet provisioned for this repo |
| RED-2 defer verdict | HIGH | YubiKey/automated issuance conflict is fundamental; VC-JWT has one signature (frozen) |
| RED-2 WKD on checkpoint | HIGH | Logically sound; no technical unknowns |
| RED-3 LDAP transparency | HIGH | RoleService.php uses IGroupManager today; NC Group_LDAP.php implements GroupInterface |
| RED-3 assignment schema | HIGH | Mirrors Version009100 patterns exactly; index names/lengths verified |
| RED-3 `scope_group_id` length (64) | MEDIUM | NC oc_groups.gid is VARCHAR(64); confirm AWO LDAP CN lengths before deploy |
| KeyService return shape `['key','secret']` | HIGH | Verified from KeyService.php line 184 — keyed array, not positional tuple |

---

## Sources

- `app/lib/Service/AuditService.php` — current `logEvent()` shape, try/catch behavior
- `app/lib/Service/SigningService.php` — frozen `typ:vc+jwt` contract (155-ADR-ANCHOR); reason for separate AuditCheckpointService
- `app/lib/Service/KeyService.php` — `getActiveSigningMaterial()` returns `['key' => CertKey, 'secret' => string]` (line 184); `sodium_memzero` pattern; key infrastructure shared by checkpoint
- `app/lib/Service/RoleService.php` — IGroupManager usage in production; LDAP transparency proof
- `app/lib/Service/PassCriteriaService.php` — `emitPassEventIfFirst()` audit-event guard (Crux 3 source)
- `app/lib/Migration/Version001900Date20260311020000.php` — `learning_audit_events` schema
- `app/lib/Migration/Version009100Date20260627000000.php` — `active_idem_key` nullable-unique pattern, TEXT NOT indexed, index ≤27 chars
- `app/lib/Migration/Version009200Date20260627120000.php` — ALTER table addColumn guard pattern
- `.planning/research/ARCHITECTURE.md` — `learning_team_leads` (superseded), re-cert cruxes, emitPassEventIfFirst open question
- `~/ObsidianVaults/Personal/Projekte/Learning-NC/App-Requirements-Compliance-Business.md` — business-layer handover
- [github.com/nextcloud/server — IQueryBuilder.php](https://github.com/nextcloud/server/blob/master/lib/public/DB/QueryBuilder/IQueryBuilder.php) — confirmed: no `forUpdate()` method in OCP interface
- [github.com/nextcloud/server — user_ldap/lib/Group_LDAP.php](https://github.com/nextcloud/server/blob/master/apps/user_ldap/lib/Group_LDAP.php) — LDAP backend implements GroupInterface behind IGroupManager
- [docs.nextcloud.com — User authentication with LDAP](https://docs.nextcloud.com/server/stable/admin_manual/configuration_user/user_auth_ldap.html) — LDAP groups exposed via NC group APIs

---

*Stack foundations research for: v5.2.0 Pflichtschulung / AWO-Readiness — learning-nc*
*Researched: 2026-07-01*

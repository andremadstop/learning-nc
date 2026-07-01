# Audit Fork Resolution Runbook

**When to use:** `occ learning:audit:verify` exits 1 and reports a chain break, signature mismatch,
or anchor mismatch.
**Who:** System Administrator with Nextcloud shell access.
**Goal:** Identify what changed, preserve evidence, restore chain integrity.

---

## 1. Detect — Understand the Scope

Run the verifier and capture output:
```
occ learning:audit:verify --json > /tmp/audit-verify-$(date +%Y%m%d-%H%M).json
cat /tmp/audit-verify-*.json
```

Note:
- Which `seq_num` values are reported broken
- Whether checkpoint signatures are invalid (key rotation? payload drift?)
- Whether `anchor_url` content differs from `signed_payload` (external tamper evidence)

## 2. Preserve — Do Not Touch the Chain

**Before any action, take a database snapshot** of the three audit tables:
```
# PostgreSQL (devcloud / Relay):
docker exec devcloud-db pg_dump -U oc_admin nextcloud \
    -t "oc_learning_audit_events" \
    -t "oc_learning_audit_chain_state" \
    -t "oc_learning_audit_checkpoints" \
    > /tmp/audit-snapshot-$(date +%Y%m%d-%H%M).sql

# MariaDB:
mysqldump -u oc_admin -p nextcloud \
    oc_learning_audit_events oc_learning_audit_chain_state oc_learning_audit_checkpoints \
    > /tmp/audit-snapshot-$(date +%Y%m%d-%H%M).sql
```

**Cross-reference the Forgejo anchor** (if enabled):
- Open the `anchor_url` from `oc_learning_audit_checkpoints` in a browser
- Compare the anchored file content against the `signed_payload` column of the same checkpoint row
- A mismatch means the DB was modified AFTER anchoring — strong, admin-independent tamper evidence
  (the Forgejo commit time is outside the NC admin's control).

## 3. Classify — Determine Root Cause

| Symptom | Likely Cause | Action |
|---------|-------------|--------|
| Single event `chain_hash` wrong, surrounding events OK | Direct DB edit of that row | Preserve snapshot, escalate to DPO |
| All events after seq N wrong | seq N row was edited, breakage propagated | Preserve; reconstruct expected state from the Forgejo anchor |
| Checkpoint signature invalid, chain itself intact | Key rotation without a fresh checkpoint | Re-run `occ learning:audit:verify` after re-checkpointing (Level 1 below) |
| `user_id` NULL but row still verifies | DSGVO-01 Art.17 erasure — **NOT tamper** | No action; `user_ref`/`chain_hash` are immutable by design |
| Anchor content differs from `signed_payload` | DB edit after anchoring | Strongest tamper evidence — escalate immediately |
| verify passes after `occ upgrade` | Migration not applied | Run `occ upgrade`, re-verify |

## 4. Resolve — Options by Severity

### Level 1: Configuration issue (not tamper)
- Missing/rotated signing key → rotate + re-checkpoint:
  `php occ learning:cert:init-issuer --rotate` then let the weekly `AuditCheckpointJob` re-sign
  (or trigger a checkpoint per the operations notes).
- Migration missing → `php occ upgrade`, then `occ learning:audit:verify`.

### Level 2: Single corrupted row (data-integrity issue)
- Restore the affected row from the database snapshot (Section 2) — original bytes only.
- Re-run `occ learning:audit:verify`; if clean, document the incident in the Datenschutz-Log.
- **Do NOT recompute or hand-edit `chain_hash` / `user_ref`** — restore the original stored bytes.

### Level 3: Deliberate tamper (escalation required)
1. Do not modify anything further.
2. Export the snapshot + the Forgejo anchor URLs as evidence.
3. Contact the Datenschutzbeauftragter (DPO) immediately.
4. Preserve server state for forensic analysis (do NOT run `occ upgrade` or maintenance).

## 5. Verify Fix

After any remediation:
```
occ learning:audit:verify
# Must exit 0 before marking the incident resolved.
```

## Reference

- Chain design & 6-field canonical: Phase 160 migration notes + Phase 161 RESEARCH.md §Pattern 2
- DSGVO-01 erasure compatibility (`user_id` NULL is not tamper): Phase 160 RESEARCH.md §DSGVO-01
- Checkpoint signing (Ed25519 / `signed_payload` verbatim): Phase 161 RESEARCH.md §Pattern 1
- Forgejo anchor mechanics: Phase 161 RESEARCH.md §AUDIT-05 / Pitfall 7
- Current audit config: `occ config:list learning | grep -E "audit|forgejo|checkpoint"`

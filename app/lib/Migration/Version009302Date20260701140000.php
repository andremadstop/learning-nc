<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Phase 161 — Audit Hardening (AUDIT-04).
 *
 * Creates learning_audit_checkpoints: tamper-evident Ed25519 checkpoints over the Phase-160
 * hash chain. Each row is a signed artifact an external verifier can check WITHOUT DB access —
 * it commits to a window [from_event_id, to_event_id] of the compliance chain and stores the
 * verbatim JSON bytes that were signed (signed_payload), so re-verification never has to
 * reconstruct the canonical form (no reconstruction drift).
 *
 * Columns (10):
 *   - id             BIGINT PK autoincrement
 *   - from_event_id  BIGINT NOT NULL  (first seq_num in the checkpoint window)
 *   - to_event_id    BIGINT NOT NULL  (last seq_num; used by audit:verify to find the tail event)
 *   - head_hash      VARCHAR(64) NOT NULL  (chain_hash of the to_event_id row)
 *   - event_count    BIGINT NOT NULL
 *   - signed_at      BIGINT NOT NULL  (unix timestamp of signing)
 *   - key_id         VARCHAR(255) NOT NULL  (CertKey::getKeyId() — needed for post-rotation verify)
 *   - signed_payload TEXT NOT NULL   (verbatim JSON passed to sodium_crypto_sign_detached)
 *   - signature      VARCHAR(128) NOT NULL  (hex-encoded raw Ed25519 sig: 64 bytes = 128 hex chars)
 *   - anchor_url     VARCHAR(500) NULL  (Forgejo HTML URL set by AUDIT-05; NULL = not anchored yet)
 *
 * Indexes (names ≤27 chars for MariaDB):
 *   - learn_aud_chk_to_idx  on (to_event_id)  — 20 chars
 *   - learn_aud_chk_at_idx  on (signed_at)    — 20 chars
 *
 * Idempotent: hasTable guard makes a repeated occ upgrade a no-op.
 * PG16 + MariaDB 11.4 safe: all types from OCP\DB\Types constants, no raw SQL.
 */
class Version009302Date20260701140000 extends SimpleMigrationStep {

    /**
     * @param Closure(): ISchemaWrapper $schemaClosure
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('learning_audit_checkpoints')) {
            return null;
        }

        $table = $schema->createTable('learning_audit_checkpoints');
        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
        $table->addColumn('from_event_id', Types::BIGINT, ['notnull' => true]);
        $table->addColumn('to_event_id', Types::BIGINT, ['notnull' => true]);
        $table->addColumn('head_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('event_count', Types::BIGINT, ['notnull' => true]);
        $table->addColumn('signed_at', Types::BIGINT, ['notnull' => true]);
        $table->addColumn('key_id', Types::STRING, ['notnull' => true, 'length' => 255]);
        // TEXT (not VARCHAR): stores unbounded verbatim JSON signing input.
        $table->addColumn('signed_payload', Types::TEXT, ['notnull' => true]);
        $table->addColumn('signature', Types::STRING, ['notnull' => true, 'length' => 128]);
        // Nullable: NULL when the Forgejo anchor is not configured or the anchor POST failed.
        $table->addColumn('anchor_url', Types::STRING, ['notnull' => false, 'length' => 500]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['to_event_id'], 'learn_aud_chk_to_idx');
        $table->addIndex(['signed_at'], 'learn_aud_chk_at_idx');

        return $schema;
    }
}

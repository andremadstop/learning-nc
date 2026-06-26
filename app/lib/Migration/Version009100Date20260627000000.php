<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Phase 155 — Certificate-Artifact & Issuer: data layer (entry gate).
 *
 * Creates the two storage tables for verifiable certificates. NO signing or
 * key-generation logic lives here — only schema. Signing is 155-02/03.
 *
 * learning_cert_keys     — issuer signing keys, the rotation foundation.
 *   secret_key_enc holds an ICrypto ciphertext (NEVER plaintext); CertKey::jsonSerialize()
 *   structurally omits it. status: 'active' | 'retired' | 'revoked'.
 * learning_certificates  — issued credentials (the signed compact JWT, public + shareable).
 *   key_id references learning_cert_keys.key_id so rotation never invalidates past certs.
 *
 * Cross-DB go/no-go (PostgreSQL 16 + MariaDB 11.4 utf8mb4) is verified in 155-07.
 * Constraints applied here:
 *   - Table names WITHOUT oc_ prefix — NC auto-prepends (oc_ here = double-prefix bug).
 *   - Types::* everywhere (BIGINT/STRING/TEXT/BOOLEAN) — NC maps per-platform.
 *   - credential_json is TEXT and is NOT indexed (utf8mb4 key-length blowup on MariaDB).
 *   - All index names <= 27 chars.
 */
class Version009100Date20260627000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;

        if (!$schema->hasTable('learning_cert_keys')) {
            $table = $schema->createTable('learning_cert_keys');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('key_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('public_key_b64u', Types::STRING, ['notnull' => true, 'length' => 128]);
            $table->addColumn('secret_key_enc', Types::TEXT, ['notnull' => true]);
            $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'active']);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['key_id'], 'learn_certkey_kid_uniq');
            $changed = true;
        }

        if (!$schema->hasTable('learning_certificates')) {
            $table = $schema->createTable('learning_certificates');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('verification_id', Types::STRING, ['notnull' => true, 'length' => 36]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('course_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('key_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('credential_json', Types::TEXT, ['notnull' => true]);
            $table->addColumn('revoked', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->addColumn('issued_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->addColumn('expires_at', Types::BIGINT, ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['verification_id'], 'learn_cert_vid_uniq');
            $table->addIndex(['user_id', 'course_id'], 'learn_cert_user_crs_idx');
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}

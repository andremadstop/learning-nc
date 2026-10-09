<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 5.7.0 AI consent bound to a recipient, plus the LLM budget table (spec 3.1, 2.4).
 *
 * learning_user_telos
 *   ai_consent_fingerprint  recipient fingerprint the consent was given for (sha256 hex)
 *   ai_consent_source       dialog|onboarding
 *   ai_consent_at           unix time of the consent
 *   consent_epoch           raised on every revocation
 * The consent columns stay NULL on upgrade (no backfill): a consent without recipient is not valid.
 *
 * learning_llm_budget — one row per budget bucket and window; atomic reservations (Task B2).
 *
 * learning_ai_chat_memory
 *   swarm_sourced  1 = answer/summary built with another learner's contribution, 0 = without;
 *                  NULL on upgrade (no backfill): rows from before 5.7.0 are of unknown origin and
 *                  are not sent to an external recipient (spec 3.6).
 *
 * In changeSchema(): a fresh install runs only that (see Version010100).
 */
class Version010500Date20261009000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;

        if ($schema->hasTable('learning_user_telos')) {
            $table = $schema->getTable('learning_user_telos');
            if (!$table->hasColumn('ai_consent_fingerprint')) {
                $table->addColumn('ai_consent_fingerprint', Types::STRING, ['notnull' => false, 'length' => 64]);
                $changed = true;
            }
            if (!$table->hasColumn('ai_consent_source')) {
                $table->addColumn('ai_consent_source', Types::STRING, ['notnull' => false, 'length' => 16]);
                $changed = true;
            }
            if (!$table->hasColumn('ai_consent_at')) {
                $table->addColumn('ai_consent_at', Types::BIGINT, ['notnull' => false]);
                $changed = true;
            }
            if (!$table->hasColumn('consent_epoch')) {
                $table->addColumn('consent_epoch', Types::INTEGER, ['notnull' => true, 'default' => 0]);
                $changed = true;
            }
        }

        if ($schema->hasTable('learning_ai_chat_memory')) {
            $table = $schema->getTable('learning_ai_chat_memory');
            if (!$table->hasColumn('swarm_sourced')) {
                $table->addColumn('swarm_sourced', Types::SMALLINT, ['notnull' => false]);
                $changed = true;
            }
        }

        if (!$schema->hasTable('learning_llm_budget')) {
            $table = $schema->createTable('learning_llm_budget');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('bucket', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('window_start', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('count', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->addColumn('inflight', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['bucket', 'window_start'], 'llm_budget_bucket_uq');
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}

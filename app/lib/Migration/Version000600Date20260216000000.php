<?php
declare(strict_types=1);
namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v1.2.4: Add UNIQUE constraint on pool_shares(pool_id, shared_with, share_type).
 */
class Version000600Date20260216000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('learning_pool_shares')) {
            $table = $schema->getTable('learning_pool_shares');
            if (!$table->hasIndex('learn_ps_pool_user_uniq')) {
                $table->addUniqueIndex(['pool_id', 'shared_with', 'share_type'], 'learn_ps_pool_user_uniq');
            }
        }

        return $schema;
    }
}

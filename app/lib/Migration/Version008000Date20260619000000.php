<?php
declare(strict_types=1);
namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Issue #24: learning_user_stats was created without a primary key.
 *
 * user_id is unique and all access paths address this table by user_id, so it
 * is the natural primary key. The old unique index becomes redundant.
 */
class Version008000Date20260619000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('learning_user_stats')) {
            return null;
        }

        $table = $schema->getTable('learning_user_stats');
        if (!$table->hasColumn('user_id') || $table->getPrimaryKey() !== null) {
            return null;
        }

        if ($table->hasIndex('learn_ustats_user_uniq')) {
            $table->dropIndex('learn_ustats_user_uniq');
        }

        $table->setPrimaryKey(['user_id']);

        return $schema;
    }
}

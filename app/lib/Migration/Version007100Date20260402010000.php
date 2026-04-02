<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version007100Date20260402010000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('learning_leitner_items')) {
            return null;
        }

        $table = $schema->getTable('learning_leitner_items');
        if ($table->hasIndex('learn_lt_user_stability')) {
            return null;
        }

        $table->addIndex(['user_id', 'stability'], 'learn_lt_user_stability');

        return $schema;
    }
}

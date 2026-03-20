<?php
declare(strict_types=1);
namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001600Date20260227000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('learning_user_stats')) {
            $table = $schema->getTable('learning_user_stats');
            if (!$table->hasColumn('last_challenge_date')) {
                $table->addColumn('last_challenge_date', Types::STRING, [
                    'notnull' => false,
                    'length' => 10,
                    'default' => null,
                ]);
            }
            if (!$table->hasColumn('last_challenge_correct')) {
                $table->addColumn('last_challenge_correct', Types::BOOLEAN, [
                    'notnull' => false,
                    'default' => null,
                ]);
            }
        }

        return $schema;
    }
}

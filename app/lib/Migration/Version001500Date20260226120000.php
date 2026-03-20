<?php
declare(strict_types=1);
namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001500Date20260226120000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('learning_user_stats')) {
            $table = $schema->getTable('learning_user_stats');
            if (!$table->hasColumn('daily_goal')) {
                $table->addColumn('daily_goal', Types::INTEGER, [
                    'notnull' => true,
                    'default' => 20,
                    'unsigned' => true,
                ]);
            }
        }

        return $schema;
    }
}

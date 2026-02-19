<?php
namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000900Date20260219000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('learning_sessions')) {
            $table = $schema->getTable('learning_sessions');
            if (!$table->hasColumn('mode')) {
                $table->addColumn('mode', Types::STRING, [
                    'notnull' => true,
                    'length' => 20,
                    'default' => 'training',
                ]);
            }
        }

        return $schema;
    }
}

<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version004700Date20260324010000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('learning_courses')) {
            return $schema;
        }

        $table = $schema->getTable('learning_courses');
        if (!$table->hasColumn('enabled_tools')) {
            $table->addColumn('enabled_tools', Types::TEXT, [
                'notnull' => false,
                'default' => null,
            ]);
        }

        return $schema;
    }
}

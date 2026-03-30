<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version006400Date20260330000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('learning_courses')) {
            $table = $schema->getTable('learning_courses');

            if (!$table->hasColumn('exam_date')) {
                $table->addColumn('exam_date', Types::STRING, [
                    'notnull' => false,
                    'length' => 10,
                    'default' => null,
                ]);
            }
        }

        return $schema;
    }
}

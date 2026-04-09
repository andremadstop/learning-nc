<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add maintenance_mode column to learning_courses.
 * When true, the course is in post-course retention mode,
 * giving students a lightweight daily review queue.
 */
class Version007600Date20260408120000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('learning_courses')) {
            $table = $schema->getTable('learning_courses');
            if (!$table->hasColumn('maintenance_mode')) {
                $table->addColumn('maintenance_mode', Types::BOOLEAN, [
                    'notnull' => true,
                    'default' => false,
                ]);
            }
        }

        return $schema;
    }
}

<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Widen exam_date column from varchar(10) to varchar(16)
 * to support datetime-local format "YYYY-MM-DDTHH:MM".
 */
class Version007700Date20260409000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('learning_courses')) {
            $table = $schema->getTable('learning_courses');
            if ($table->hasColumn('exam_date')) {
                $column = $table->getColumn('exam_date');
                $column->setLength(16);
            }
        }

        return $schema;
    }
}

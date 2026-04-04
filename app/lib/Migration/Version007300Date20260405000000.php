<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version007300Date20260405000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('learning_course_pools')) {
            return null;
        }

        $table = $schema->getTable('learning_course_pools');

        if ($table->hasColumn('exam_relevant')) {
            return null;
        }

        $table->addColumn('exam_relevant', Types::BOOLEAN, [
            'notnull' => true,
            'default' => false,
        ]);

        return $schema;
    }
}

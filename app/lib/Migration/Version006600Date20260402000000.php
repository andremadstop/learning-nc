<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add allowed_campaigns column to learning_courses.
 * Stores a JSON array of campaign IDs that are enabled for the course.
 * NULL means all campaigns are available (default).
 */
class Version006600Date20260402000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('learning_courses')) {
            $table = $schema->getTable('learning_courses');
            if (!$table->hasColumn('allowed_campaigns')) {
                $table->addColumn('allowed_campaigns', Types::TEXT, [
                    'notnull' => false,
                    'default' => null,
                ]);
            }
        }

        return $schema;
    }
}

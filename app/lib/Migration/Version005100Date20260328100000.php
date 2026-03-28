<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Phase 100: Add talk_room_token and leitner_sprint columns to courses table.
 */
class Version005100Date20260328100000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('learning_courses')) {
            $table = $schema->getTable('learning_courses');

            if (!$table->hasColumn('talk_room_token')) {
                $table->addColumn('talk_room_token', Types::STRING, [
                    'notnull' => false,
                    'length' => 255,
                    'default' => null,
                ]);
            }

            if (!$table->hasColumn('leitner_sprint')) {
                $table->addColumn('leitner_sprint', Types::BOOLEAN, [
                    'notnull' => false,
                    'default' => false,
                ]);
            }
        }

        return $schema;
    }
}

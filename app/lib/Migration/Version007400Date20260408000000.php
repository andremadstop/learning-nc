<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version007400Date20260408000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('learning_course_schedule')) {
            return null;
        }

        $table = $schema->createTable('learning_course_schedule');

        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull' => true,
            'length' => 20,
        ]);
        $table->addColumn('course_id', Types::BIGINT, [
            'notnull' => true,
            'length' => 20,
        ]);
        $table->addColumn('chapter_ref', Types::STRING, [
            'notnull' => true,
            'length' => 255,
        ]);
        $table->addColumn('chapter_title', Types::STRING, [
            'notnull' => false,
            'length' => 255,
        ]);
        $table->addColumn('start_date', Types::DATE, [
            'notnull' => false,
        ]);
        $table->addColumn('target_date', Types::DATE, [
            'notnull' => false,
        ]);
        $table->addColumn('sort_order', Types::INTEGER, [
            'notnull' => true,
            'default' => 0,
        ]);
        $table->addColumn('created_at', Types::DATETIME, [
            'notnull' => true,
        ]);
        $table->addColumn('updated_at', Types::DATETIME, [
            'notnull' => true,
        ]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['course_id'], 'learning_cs_course_idx');
        $table->addUniqueIndex(['course_id', 'chapter_ref'], 'learning_cs_course_chapter_uniq');

        return $schema;
    }
}

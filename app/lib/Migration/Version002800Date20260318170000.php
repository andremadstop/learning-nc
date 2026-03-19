<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version002800Date20260318170000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        if ($schema->hasTable('learning_pools')) {
            $table = $schema->getTable('learning_pools');
            if (!$table->hasColumn('handbook_key')) {
                $table->addColumn('handbook_key', Types::STRING, ['notnull' => false, 'length' => 64]);
            }
            if (!$table->hasColumn('handbook_title')) {
                $table->addColumn('handbook_title', Types::STRING, ['notnull' => false, 'length' => 255]);
            }
            if (!$table->hasColumn('chapter_key')) {
                $table->addColumn('chapter_key', Types::STRING, ['notnull' => false, 'length' => 64]);
            }
            if (!$table->hasColumn('chapter_title')) {
                $table->addColumn('chapter_title', Types::STRING, ['notnull' => false, 'length' => 255]);
            }
            if (!$table->hasColumn('chapter_order')) {
                $table->addColumn('chapter_order', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
            }
            if (!$table->hasIndex('learn_pool_chapter_idx')) {
                $table->addIndex(['handbook_key', 'chapter_key', 'chapter_order'], 'learn_pool_chapter_idx');
            }
        }

        if ($schema->hasTable('learning_league_seasons')) {
            $table = $schema->getTable('learning_league_seasons');
            if (!$table->hasColumn('handbook_key')) {
                $table->addColumn('handbook_key', Types::STRING, ['notnull' => false, 'length' => 64]);
            }
            if (!$table->hasColumn('handbook_title')) {
                $table->addColumn('handbook_title', Types::STRING, ['notnull' => false, 'length' => 255]);
            }
            if (!$table->hasColumn('chapter_key')) {
                $table->addColumn('chapter_key', Types::STRING, ['notnull' => false, 'length' => 64]);
            }
            if (!$table->hasColumn('chapter_title')) {
                $table->addColumn('chapter_title', Types::STRING, ['notnull' => false, 'length' => 255]);
            }
            if (!$table->hasColumn('chapter_order')) {
                $table->addColumn('chapter_order', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
            }
            if (!$table->hasColumn('cl_handbook_key')) {
                $table->addColumn('cl_handbook_key', Types::STRING, ['notnull' => false, 'length' => 64]);
            }
            if (!$table->hasColumn('cl_handbook_title')) {
                $table->addColumn('cl_handbook_title', Types::STRING, ['notnull' => false, 'length' => 255]);
            }
            if (!$table->hasColumn('cl_chapter_key')) {
                $table->addColumn('cl_chapter_key', Types::STRING, ['notnull' => false, 'length' => 64]);
            }
            if (!$table->hasColumn('cl_chapter_title')) {
                $table->addColumn('cl_chapter_title', Types::STRING, ['notnull' => false, 'length' => 255]);
            }
            if (!$table->hasColumn('cl_chapter_order')) {
                $table->addColumn('cl_chapter_order', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
            }
            if (!$table->hasIndex('lls_course_chapter_idx')) {
                $table->addIndex(['course_id', 'handbook_key', 'chapter_key'], 'lls_course_chapter_idx');
            }
        }

        return $schema;
    }
}

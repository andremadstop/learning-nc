<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version003000Date20260319130000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        if ($schema->hasTable('learning_course_pools')) {
            $table = $schema->getTable('learning_course_pools');
            if (!$table->hasColumn('required_enforced')) {
                $table->addColumn('required_enforced', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
            }
            if (!$table->hasColumn('filter_exam_key')) {
                $table->addColumn('filter_exam_key', Types::STRING, ['notnull' => false, 'length' => 64]);
            }
            if (!$table->hasColumn('filter_chapter_key')) {
                $table->addColumn('filter_chapter_key', Types::STRING, ['notnull' => false, 'length' => 64]);
            }
            if (!$table->hasColumn('filter_question_ids')) {
                $table->addColumn('filter_question_ids', Types::TEXT, ['notnull' => false]);
            }
            if (!$table->hasIndex('learning_cp_filter_idx')) {
                $table->addIndex(['course_id', 'pool_id', 'required_enforced'], 'learning_cp_filter_idx');
            }
        }

        if ($schema->hasTable('learning_sessions')) {
            $table = $schema->getTable('learning_sessions');
            if (!$table->hasColumn('course_id')) {
                $table->addColumn('course_id', Types::BIGINT, ['notnull' => false, 'length' => 8]);
            }
            if (!$table->hasIndex('learn_sess_course_pool_idx')) {
                $table->addIndex(['course_id', 'pool_id', 'user_id'], 'learn_sess_course_pool_idx');
            }
        }

        if ($schema->hasTable('learning_duel_sessions')) {
            $table = $schema->getTable('learning_duel_sessions');
            if (!$table->hasColumn('course_id')) {
                $table->addColumn('course_id', Types::BIGINT, ['notnull' => false, 'length' => 8]);
            }
            if (!$table->hasIndex('learn_duel_course_pool_idx')) {
                $table->addIndex(['course_id', 'pool_id'], 'learn_duel_course_pool_idx');
            }
        }

        return $schema;
    }
}

<?php
declare(strict_types=1);
namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001400Date20260226000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Index for leaderboard/student-detail: Leitner items per user per pool
        if ($schema->hasTable('learning_leitner_items')) {
            $table = $schema->getTable('learning_leitner_items');
            if (!$table->hasIndex('learn_lt_user_pool_box')) {
                $table->addIndex(['user_id', 'pool_id', 'box'], 'learn_lt_user_pool_box');
            }
        }

        // Index for session aggregations per user per pool
        if ($schema->hasTable('learning_sessions')) {
            $table = $schema->getTable('learning_sessions');
            if (!$table->hasIndex('learn_sess_user_pool_completed')) {
                $table->addIndex(['user_id', 'pool_id', 'completed_at'], 'learn_sess_user_pool_completed');
            }
        }

        // Index for course member lookups by course + role
        if ($schema->hasTable('learning_course_members')) {
            $table = $schema->getTable('learning_course_members');
            if (!$table->hasIndex('learn_cm_course_role')) {
                $table->addIndex(['course_id', 'role'], 'learn_cm_course_role');
            }
        }

        return $schema;
    }
}

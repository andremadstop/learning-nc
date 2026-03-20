<?php
declare(strict_types=1);
namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version003500Date20260319240000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Table: learning_course_question_overrides
        if (!$schema->hasTable('learning_course_question_overrides')) {
            $table = $schema->createTable('learning_course_question_overrides');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('course_id', 'integer', ['notnull' => true]);
            $table->addColumn('question_id', 'integer', ['notnull' => true]);
            $table->addColumn('paused', 'boolean', ['notnull' => false, 'default' => false]);
            $table->addColumn('highlight', 'boolean', ['notnull' => false, 'default' => false]);
            $table->addColumn('created_at', 'integer', ['notnull' => false]);
            $table->addColumn('updated_at', 'integer', ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['course_id', 'question_id'], 'cqo_course_question_unique');
        }

        // Table: learning_course_announcements
        if (!$schema->hasTable('learning_course_announcements')) {
            $table = $schema->createTable('learning_course_announcements');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('course_id', 'integer', ['notnull' => true]);
            $table->addColumn('instructor_id', 'string', ['notnull' => false, 'length' => 64]);
            $table->addColumn('title', 'string', ['notnull' => true, 'length' => 255, 'default' => '']);
            $table->addColumn('body', 'text', ['notnull' => false]);
            $table->addColumn('created_at', 'integer', ['notnull' => false]);
            $table->addColumn('expires_at', 'integer', ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['course_id'], 'ca_course_id_idx');
        }

        // Table: learning_course_exam_slots
        if (!$schema->hasTable('learning_course_exam_slots')) {
            $table = $schema->createTable('learning_course_exam_slots');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('course_id', 'integer', ['notnull' => true]);
            $table->addColumn('instructor_id', 'string', ['notnull' => false, 'length' => 64]);
            $table->addColumn('started_at', 'integer', ['notnull' => false]);
            $table->addColumn('duration_minutes', 'integer', ['notnull' => true, 'default' => 90]);
            $table->addColumn('ends_at', 'integer', ['notnull' => false]);
            $table->addColumn('scope_mode', 'string', ['notnull' => true, 'length' => 16, 'default' => 'all']);
            $table->addColumn('question_ids_json', 'text', ['notnull' => false]);
            $table->addColumn('status', 'string', ['notnull' => true, 'length' => 16, 'default' => 'active']);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['course_id', 'status'], 'ces_course_status_idx');
        }

        // Add routing columns to learning_support_tickets
        if ($schema->hasTable('learning_support_tickets')) {
            $table = $schema->getTable('learning_support_tickets');
            if (!$table->hasColumn('category')) {
                $table->addColumn('category', 'string', ['notnull' => false, 'length' => 32, 'default' => 'technical']);
            }
            if (!$table->hasColumn('routing_target_type')) {
                $table->addColumn('routing_target_type', 'string', ['notnull' => false, 'length' => 32, 'default' => 'admin']);
            }
            if (!$table->hasColumn('routing_course_id')) {
                $table->addColumn('routing_course_id', 'integer', ['notnull' => false]);
            }
        }

        // Add mode config columns to learning_courses
        if ($schema->hasTable('learning_courses')) {
            $table = $schema->getTable('learning_courses');
            if (!$table->hasColumn('mode_config')) {
                $table->addColumn('mode_config', 'text', ['notnull' => false]);
            }
            if (!$table->hasColumn('exam_available_from')) {
                $table->addColumn('exam_available_from', 'integer', ['notnull' => false]);
            }
            if (!$table->hasColumn('exam_attempts_per_day')) {
                $table->addColumn('exam_attempts_per_day', 'integer', ['notnull' => false]);
            }
            if (!$table->hasColumn('exam_requires_training')) {
                $table->addColumn('exam_requires_training', 'boolean', ['notnull' => false, 'default' => false]);
            }
        }

        return $schema;
    }
}

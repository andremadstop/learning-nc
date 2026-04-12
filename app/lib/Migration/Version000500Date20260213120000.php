<?php
namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000500Date20260213120000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Table 1: learning_courses
        if (!$schema->hasTable('learning_courses')) {
            $table = $schema->createTable('learning_courses');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            $table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('description', Types::TEXT, ['notnull' => false]);
            $table->addColumn('instructor_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('nc_group_id', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'active']);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 8]);
            $table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'length' => 8]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['instructor_id'], 'learn_crs_instructor');
            $table->addIndex(['nc_group_id'], 'learning_courses_group');
        }

        // Table 2: learning_course_pools
        if (!$schema->hasTable('learning_course_pools')) {
            $table = $schema->createTable('learning_course_pools');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            $table->addColumn('course_id', Types::BIGINT, ['notnull' => true, 'length' => 8]);
            $table->addColumn('pool_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->addColumn('required', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['course_id', 'pool_id'], 'learning_cp_unique');
            $table->addIndex(['course_id'], 'learning_cp_course');
            $table->addIndex(['pool_id'], 'learning_cp_pool');
        }

        // Table 3: learning_course_members
        if (!$schema->hasTable('learning_course_members')) {
            $table = $schema->createTable('learning_course_members');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            $table->addColumn('course_id', Types::BIGINT, ['notnull' => true, 'length' => 8]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('role', Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'student']);
            $table->addColumn('enrolled_at', Types::BIGINT, ['notnull' => true, 'length' => 8]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['course_id', 'user_id'], 'learning_cm_unique');
            $table->addIndex(['user_id'], 'learning_cm_user');
            $table->addIndex(['course_id'], 'learning_cm_course');
        }

        return $schema;
    }
}

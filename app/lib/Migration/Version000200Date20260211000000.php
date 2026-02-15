<?php
declare(strict_types=1);
namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000200Date20260211000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        // Questions table
        if (!$schema->hasTable('learning_questions')) {
            $table = $schema->createTable('learning_questions');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('pool_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('text', Types::TEXT, ['notnull' => true]);
            $table->addColumn('explanation', Types::TEXT, ['notnull' => false]);
            $table->addColumn('difficulty', Types::STRING, ['notnull' => false, 'length' => 20]);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['pool_id'], 'learn_q_pool_idx');
            $table->addIndex(['user_id'], 'learn_q_user_idx');
        }

        // Answers table
        if (!$schema->hasTable('learning_answers')) {
            $table = $schema->createTable('learning_answers');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('question_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('text', Types::TEXT, ['notnull' => true]);
            $table->addColumn('is_correct', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
            $table->addColumn('position', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['question_id'], 'learn_a_question_idx');
        }

        return $schema;
    }
}

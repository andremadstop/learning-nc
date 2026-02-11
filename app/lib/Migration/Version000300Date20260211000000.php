<?php
declare(strict_types=1);
namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000300Date20260211000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        // Training sessions
        if (!$schema->hasTable('learning_sessions')) {
            $table = $schema->createTable('learning_sessions');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('pool_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('started_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('completed_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
            $table->addColumn('total_questions', Types::INTEGER, ['notnull' => true]);
            $table->addColumn('correct_answers', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['pool_id'], 'learning_sessions_pool_id');
            $table->addIndex(['user_id'], 'learning_sessions_user_id');
        }

        // User answers
        if (!$schema->hasTable('learning_user_answers')) {
            $table = $schema->createTable('learning_user_answers');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('session_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('question_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('answer_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('is_correct', Types::BOOLEAN, ['notnull' => true]);
            $table->addColumn('answered_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['session_id'], 'learning_user_answers_session_id');
            $table->addIndex(['question_id'], 'learning_user_answers_question_id');
        }

        // Leitner boxes
        if (!$schema->hasTable('learning_leitner_items')) {
            $table = $schema->createTable('learning_leitner_items');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('pool_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('question_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('box', Types::INTEGER, ['notnull' => true, 'default' => 1]); // 1-5
            $table->addColumn('next_review', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('correct_count', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->addColumn('incorrect_count', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->addColumn('last_reviewed', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id', 'pool_id'], 'learning_leitner_user_pool');
            $table->addIndex(['next_review'], 'learning_leitner_next_review');
            $table->addUniqueIndex(['user_id', 'question_id'], 'learning_leitner_user_question');
        }

        return $schema;
    }
}

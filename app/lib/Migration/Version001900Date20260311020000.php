<?php
declare(strict_types=1);
namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001900Date20260311020000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('learning_audit_events')) {
            $table = $schema->createTable('learning_audit_events');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('event_key', Types::STRING, ['notnull' => true, 'length' => 120]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->addColumn('session_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
            $table->addColumn('pool_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
            $table->addColumn('context_json', Types::TEXT, ['notnull' => false]);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['created_at'], 'learn_audit_created_idx');
            $table->addIndex(['event_key'], 'learn_audit_event_idx');
            $table->addIndex(['user_id'], 'learn_audit_user_idx');
            $table->addIndex(['session_id'], 'learn_audit_session_idx');
            $table->addIndex(['pool_id'], 'learn_audit_pool_idx');
        }

        if ($schema->hasTable('learning_sessions')) {
            $table = $schema->getTable('learning_sessions');
            if (!$table->hasIndex('learn_s_exam_policy_idx')) {
                $table->addIndex(['user_id', 'pool_id', 'mode', 'completed_at', 'started_at'], 'learn_s_exam_policy_idx');
            }
        }

        if ($schema->hasTable('learning_user_answers')) {
            $table = $schema->getTable('learning_user_answers');
            if (!$table->hasIndex('learn_ua_sess_quest_idx')) {
                $table->addIndex(['session_id', 'question_id'], 'learn_ua_sess_quest_idx');
            }
        }

        if ($schema->hasTable('learning_questions')) {
            $table = $schema->getTable('learning_questions');
            if (!$table->hasColumn('review_status')) {
                $table->addColumn('review_status', Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'published']);
            }
            if (!$table->hasColumn('reviewer_id')) {
                $table->addColumn('reviewer_id', Types::STRING, ['notnull' => false, 'length' => 64]);
            }
            if (!$table->hasColumn('reviewed_at')) {
                $table->addColumn('reviewed_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
            }
            if (!$table->hasIndex('learn_q_review_status_idx')) {
                $table->addIndex(['review_status'], 'learn_q_review_status_idx');
            }
            if (!$table->hasIndex('learn_q_pool_review_idx')) {
                $table->addIndex(['pool_id', 'review_status'], 'learn_q_pool_review_idx');
            }
        }

        if ($schema->hasTable('learning_pools')) {
            $table = $schema->getTable('learning_pools');
            if (!$table->hasColumn('review_status')) {
                $table->addColumn('review_status', Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'published']);
            }
            if (!$table->hasColumn('reviewer_id')) {
                $table->addColumn('reviewer_id', Types::STRING, ['notnull' => false, 'length' => 64]);
            }
            if (!$table->hasColumn('reviewed_at')) {
                $table->addColumn('reviewed_at', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
            }
            if (!$table->hasIndex('learn_pool_rev_stat_idx')) {
                $table->addIndex(['review_status'], 'learn_pool_rev_stat_idx');
            }
        }

        return $schema;
    }
}

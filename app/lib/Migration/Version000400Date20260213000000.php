<?php
declare(strict_types=1);
namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * FIX #3 + #5: Add unique index for duplicate prevention + foreign key constraints with CASCADE.
 * First cleans up orphaned records, then adds constraints.
 */
class Version000400Date20260213000000 extends SimpleMigrationStep {

    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    /**
     * Clean up orphaned records before adding foreign key constraints.
     */
    public function preSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void {
        $prefix = $this->db->getPrefix();

        // Delete questions referencing non-existent pools
        $this->db->executeStatement(
            "DELETE FROM {$prefix}learning_questions WHERE pool_id NOT IN (SELECT id FROM {$prefix}learning_pools)"
        );
        $output->info('Cleaned orphaned questions');

        // Delete answers referencing non-existent questions
        $this->db->executeStatement(
            "DELETE FROM {$prefix}learning_answers WHERE question_id NOT IN (SELECT id FROM {$prefix}learning_questions)"
        );
        $output->info('Cleaned orphaned answers');

        // Delete sessions referencing non-existent pools
        $this->db->executeStatement(
            "DELETE FROM {$prefix}learning_sessions WHERE pool_id NOT IN (SELECT id FROM {$prefix}learning_pools)"
        );
        $output->info('Cleaned orphaned sessions');

        // Delete user_answers referencing non-existent sessions
        $this->db->executeStatement(
            "DELETE FROM {$prefix}learning_user_answers WHERE session_id NOT IN (SELECT id FROM {$prefix}learning_sessions)"
        );
        $output->info('Cleaned orphaned user_answers (sessions)');

        // Delete user_answers referencing non-existent questions
        $this->db->executeStatement(
            "DELETE FROM {$prefix}learning_user_answers WHERE question_id NOT IN (SELECT id FROM {$prefix}learning_questions)"
        );
        $output->info('Cleaned orphaned user_answers (questions)');

        // Delete leitner_items referencing non-existent pools
        $this->db->executeStatement(
            "DELETE FROM {$prefix}learning_leitner_items WHERE pool_id NOT IN (SELECT id FROM {$prefix}learning_pools)"
        );
        $output->info('Cleaned orphaned leitner_items (pools)');

        // Delete leitner_items referencing non-existent questions
        $this->db->executeStatement(
            "DELETE FROM {$prefix}learning_leitner_items WHERE question_id NOT IN (SELECT id FROM {$prefix}learning_questions)"
        );
        $output->info('Cleaned orphaned leitner_items (questions)');

        // Delete pool_shares referencing non-existent pools
        $this->db->executeStatement(
            "DELETE FROM {$prefix}learning_pool_shares WHERE pool_id NOT IN (SELECT id FROM {$prefix}learning_pools)"
        );
        $output->info('Cleaned orphaned pool_shares');

        // Delete question_translations referencing non-existent questions
        $this->db->executeStatement(
            "DELETE FROM {$prefix}learning_q_translations WHERE question_id NOT IN (SELECT id FROM {$prefix}learning_questions)"
        );
        $output->info('Cleaned orphaned question_translations');

        // Delete answer_translations referencing non-existent answers
        $this->db->executeStatement(
            "DELETE FROM {$prefix}learning_a_translations WHERE answer_id NOT IN (SELECT id FROM {$prefix}learning_answers)"
        );
        $output->info('Cleaned orphaned answer_translations');

        // Remove duplicate user_answers (keep first, delete rest) for unique index
        $this->db->executeStatement(
            "DELETE FROM {$prefix}learning_user_answers WHERE id NOT IN (
                SELECT MIN(id) FROM {$prefix}learning_user_answers GROUP BY session_id, question_id
            )"
        );
        $output->info('Cleaned duplicate user_answers');
    }

    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('learning_user_answers')) {
            $table = $schema->getTable('learning_user_answers');
            if (!$table->hasIndex('learn_ua_sq_uniq')) {
                $table->addUniqueIndex(['session_id', 'question_id'], 'learn_ua_sq_uniq');
            }
        }

        // questions → pools
        if ($schema->hasTable('learning_questions') && $schema->hasTable('learning_pools')) {
            $table = $schema->getTable('learning_questions');
            if (!$this->hasForeignKey($table, 'learn_questions_pool_fk')) {
                $table->addForeignKeyConstraint(
                    $schema->getTable('learning_pools'),
                    ['pool_id'], ['id'],
                    ['onDelete' => 'CASCADE'],
                    'learn_questions_pool_fk'
                );
            }
        }

        // answers → questions
        if ($schema->hasTable('learning_answers') && $schema->hasTable('learning_questions')) {
            $table = $schema->getTable('learning_answers');
            if (!$this->hasForeignKey($table, 'learn_answers_question_fk')) {
                $table->addForeignKeyConstraint(
                    $schema->getTable('learning_questions'),
                    ['question_id'], ['id'],
                    ['onDelete' => 'CASCADE'],
                    'learn_answers_question_fk'
                );
            }
        }

        // sessions → pools
        if ($schema->hasTable('learning_sessions') && $schema->hasTable('learning_pools')) {
            $table = $schema->getTable('learning_sessions');
            if (!$this->hasForeignKey($table, 'learn_sessions_pool_fk')) {
                $table->addForeignKeyConstraint(
                    $schema->getTable('learning_pools'),
                    ['pool_id'], ['id'],
                    ['onDelete' => 'CASCADE'],
                    'learn_sessions_pool_fk'
                );
            }
        }

        // user_answers → sessions
        if ($schema->hasTable('learning_user_answers') && $schema->hasTable('learning_sessions')) {
            $table = $schema->getTable('learning_user_answers');
            if (!$this->hasForeignKey($table, 'learn_ua_session_fk')) {
                $table->addForeignKeyConstraint(
                    $schema->getTable('learning_sessions'),
                    ['session_id'], ['id'],
                    ['onDelete' => 'CASCADE'],
                    'learn_ua_session_fk'
                );
            }
        }

        // user_answers → questions
        if ($schema->hasTable('learning_user_answers') && $schema->hasTable('learning_questions')) {
            $table = $schema->getTable('learning_user_answers');
            if (!$this->hasForeignKey($table, 'learn_ua_question_fk')) {
                $table->addForeignKeyConstraint(
                    $schema->getTable('learning_questions'),
                    ['question_id'], ['id'],
                    ['onDelete' => 'CASCADE'],
                    'learn_ua_question_fk'
                );
            }
        }

        // leitner_items → pools
        if ($schema->hasTable('learning_leitner_items') && $schema->hasTable('learning_pools')) {
            $table = $schema->getTable('learning_leitner_items');
            if (!$this->hasForeignKey($table, 'learn_leitner_pool_fk')) {
                $table->addForeignKeyConstraint(
                    $schema->getTable('learning_pools'),
                    ['pool_id'], ['id'],
                    ['onDelete' => 'CASCADE'],
                    'learn_leitner_pool_fk'
                );
            }
        }

        // leitner_items → questions
        if ($schema->hasTable('learning_leitner_items') && $schema->hasTable('learning_questions')) {
            $table = $schema->getTable('learning_leitner_items');
            if (!$this->hasForeignKey($table, 'learn_leitner_question_fk')) {
                $table->addForeignKeyConstraint(
                    $schema->getTable('learning_questions'),
                    ['question_id'], ['id'],
                    ['onDelete' => 'CASCADE'],
                    'learn_leitner_question_fk'
                );
            }
        }

        // pool_shares → pools
        if ($schema->hasTable('learning_pool_shares') && $schema->hasTable('learning_pools')) {
            $table = $schema->getTable('learning_pool_shares');
            if (!$this->hasForeignKey($table, 'learn_shares_pool_fk')) {
                $table->addForeignKeyConstraint(
                    $schema->getTable('learning_pools'),
                    ['pool_id'], ['id'],
                    ['onDelete' => 'CASCADE'],
                    'learn_shares_pool_fk'
                );
            }
        }

        // question_translations → questions
        if ($schema->hasTable('learning_q_translations') && $schema->hasTable('learning_questions')) {
            $table = $schema->getTable('learning_q_translations');
            if (!$this->hasForeignKey($table, 'learn_qtrans_question_fk')) {
                $table->addForeignKeyConstraint(
                    $schema->getTable('learning_questions'),
                    ['question_id'], ['id'],
                    ['onDelete' => 'CASCADE'],
                    'learn_qtrans_question_fk'
                );
            }
        }

        // answer_translations → answers
        if ($schema->hasTable('learning_a_translations') && $schema->hasTable('learning_answers')) {
            $table = $schema->getTable('learning_a_translations');
            if (!$this->hasForeignKey($table, 'learn_atrans_answer_fk')) {
                $table->addForeignKeyConstraint(
                    $schema->getTable('learning_answers'),
                    ['answer_id'], ['id'],
                    ['onDelete' => 'CASCADE'],
                    'learn_atrans_answer_fk'
                );
            }
        }

        return $schema;
    }

    private function hasForeignKey($table, string $name): bool {
        foreach ($table->getForeignKeys() as $fk) {
            if ($fk->getName() === $name) {
                return true;
            }
        }
        return false;
    }
}

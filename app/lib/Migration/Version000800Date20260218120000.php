<?php
namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000800Date20260218120000 extends SimpleMigrationStep {
    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    /**
     * Auto-detect questions with multiple correct answers and set question_type = 'multi'
     */
    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        // Check if column already exists (idempotency)
        try {
            $this->db->executeQuery('SELECT question_type FROM *PREFIX*learning_questions LIMIT 1');
            // Column exists — run the update for any questions that might be wrong
            $this->db->executeStatement(
                "UPDATE *PREFIX*learning_questions SET question_type = 'multi' WHERE id IN (
                    SELECT question_id FROM *PREFIX*learning_answers
                    WHERE is_correct = true
                    GROUP BY question_id
                    HAVING COUNT(*) > 1
                )"
            );
        } catch (\Exception $e) {
            // Column doesn't exist yet — will be created in changeSchema, then postSchemaChange runs
        }
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Add question_type to learning_questions
        if ($schema->hasTable('learning_questions')) {
            $table = $schema->getTable('learning_questions');
            if (!$table->hasColumn('question_type')) {
                $table->addColumn('question_type', Types::STRING, [
                    'notnull' => true,
                    'length' => 20,
                    'default' => 'single',
                ]);
            }
        }

        // Add answer_ids to learning_user_answers (JSON array for multi-select responses)
        if ($schema->hasTable('learning_user_answers')) {
            $table = $schema->getTable('learning_user_answers');
            if (!$table->hasColumn('answer_ids')) {
                $table->addColumn('answer_ids', Types::TEXT, [
                    'notnull' => false,
                    'default' => null,
                ]);
            }
        }

        return $schema;
    }

    /**
     * After columns are created, set question_type = 'multi' for existing multi-correct questions
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $this->db->executeStatement(
            "UPDATE *PREFIX*learning_questions SET question_type = 'multi' WHERE id IN (
                SELECT question_id FROM *PREFIX*learning_answers
                WHERE is_correct = true
                GROUP BY question_id
                HAVING COUNT(*) > 1
            )"
        );
    }
}

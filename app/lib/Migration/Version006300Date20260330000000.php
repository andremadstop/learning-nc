<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Defensive migration: convert any true_false question_type to single.
 * As of v3.7.0, true_false is no longer a valid question type.
 * This migration is idempotent — re-running produces no changes if no rows match.
 */
class Version006300Date20260330000000 extends SimpleMigrationStep {
    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        return null; // data-only migration, no schema changes
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if (!$schema->hasTable('learning_questions')) {
            return;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->update('learning_questions')
            ->set('question_type', $qb->createNamedParameter('single'))
            ->where(
                $qb->expr()->eq('question_type', $qb->createNamedParameter('true_false'))
            );
        $qb->executeStatement();
    }
}

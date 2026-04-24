<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Repairs question_type values that slipped in through pool imports:
 *   - Legacy 'multiple' label → canonical 'multi' (Pool 124 Kammermann import)
 *   - Questions with >1 is_correct answer but question_type='single' (Pool 138/139 and
 *     any future imports that skip type detection)
 *
 * Mirrors the auto-detect in Version000800, but runs against the current data again so
 * freshly imported pools get healed without waiting for a reinstall.
 */
class Version007800Date20260424000000 extends SimpleMigrationStep {
    public function __construct(private IDBConnection $db) {
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        return null;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $legacyLabelFixed = $this->db->executeStatement(
            "UPDATE *PREFIX*learning_questions SET question_type = 'multi' WHERE question_type = 'multiple'"
        );
        $output->info(sprintf('learning: normalized %d question(s) from legacy label "multiple" to "multi"', $legacyLabelFixed));

        $multiCorrectFixed = $this->db->executeStatement(
            "UPDATE *PREFIX*learning_questions SET question_type = 'multi'
             WHERE question_type NOT IN ('multi', 'pbq', 'open')
             AND id IN (
                SELECT question_id FROM *PREFIX*learning_answers
                WHERE is_correct = true
                GROUP BY question_id
                HAVING COUNT(*) > 1
             )"
        );
        $output->info(sprintf('learning: upgraded %d question(s) with multiple correct answers to question_type=multi', $multiCorrectFixed));
    }
}

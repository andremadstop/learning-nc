<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * GitHub #84 / #87: a new certification cycle opened by an instructor, and a total limit on the
 * counting exam attempts of a course.
 *
 * learning_courses.exam_attempt_limit — at most this many counting exams per pool in the current
 *   certification cycle (ExamAttemptLimitService). NULL = unlimited, as before 5.16.0.
 * learning_assignments.reopen_reason — set on a period an instructor opened by hand
 *   (CertificationCycleService): 'revoked' (after a revoked certificate; the required documents
 *   are then confirmed again in any course), 'expired' (an expired certificate without an open
 *   period) or 'attempts' (all attempts used up). NULL for a period the recertification job
 *   opened (AssignmentService::closePeriod()).
 *
 * Columns only, both nullable without a default to fill: changeSchema() alone covers fresh
 * installs and upgrades, existing rows keep their behaviour.
 */
class Version011200Date20261118090000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;

        if ($schema->hasTable('learning_courses')) {
            $table = $schema->getTable('learning_courses');
            if (!$table->hasColumn('exam_attempt_limit')) {
                $table->addColumn('exam_attempt_limit', Types::INTEGER, ['notnull' => false, 'default' => null]);
                $changed = true;
            }
        }
        if ($schema->hasTable('learning_assignments')) {
            $table = $schema->getTable('learning_assignments');
            if (!$table->hasColumn('reopen_reason')) {
                $table->addColumn('reopen_reason', Types::STRING, ['notnull' => false, 'default' => null, 'length' => 16]);
                $changed = true;
            }
        }

        return $changed ? $schema : null;
    }
}

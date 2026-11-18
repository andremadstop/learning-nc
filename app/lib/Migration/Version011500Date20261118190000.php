<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * GitHub #87 (review): the attempt limit counts from when it was switched on.
 *
 * learning_courses.exam_attempt_limit_since — when the course's exam_attempt_limit went from
 *   unlimited to a number (CourseController::updateCertConfig(), a new mandatory-training course).
 *   ExamAttemptLimitService counts the attempts from the later of this and the start of the
 *   learner's certification cycle. Without it, a learner without a cycle (self-enrolled, enrolled
 *   by hand) whose failed attempts from before already reached the new limit was locked out the
 *   moment an instructor set it, with no cycle in which those attempts were available. NULL =
 *   no limit, or a limit from before this column (then every attempt of the cycle counts).
 *
 * A nullable column without data to fill: changeSchema() alone covers fresh installs and
 * upgrades (exam_attempt_limit itself is new in the same release, so no course has a limit yet).
 */
class Version011500Date20261118190000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('learning_courses')) {
            $table = $schema->getTable('learning_courses');
            if (!$table->hasColumn('exam_attempt_limit_since')) {
                $table->addColumn('exam_attempt_limit_since', Types::BIGINT, ['notnull' => false, 'default' => null]);
                return $schema;
            }
        }

        return null;
    }
}

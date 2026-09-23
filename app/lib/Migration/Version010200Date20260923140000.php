<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Per-course practice exams (Codeberg #9).
 *
 * learning_courses:
 *   practice_enabled      — instructor offers a practice exam in this course
 *   practice_questions    — questions drawn per attempt, across all of the course's pools
 *   practice_minutes      — time limit; 0 = untimed
 *   practice_pass_percent — pass threshold in percent
 *
 * learning_sessions:
 *   exam_kind    — 'practice' for practice exams; NULL for every other exam. Practice
 *                  sessions never count towards a certificate (CourseSummaryService::getExamScore
 *                  filters on NULL) and are left out of the anti-oracle guards.
 *   pass_percent — the threshold at the moment the attempt started, so a later change by the
 *                  instructor does not rewrite the outcome of past attempts.
 *
 * Everything lives in changeSchema(): a fresh install runs only that (see Version010100).
 * Column names stay within Nextcloud's 30-character limit.
 */
class Version010200Date20260923140000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;

        if ($schema->hasTable('learning_courses')) {
            $table = $schema->getTable('learning_courses');
            if (!$table->hasColumn('practice_enabled')) {
                // Nextcloud rejects NOT NULL booleans; same shape as cert_enabled.
                $table->addColumn('practice_enabled', Types::BOOLEAN, [
                    'notnull' => false,
                    'default' => false,
                ]);
                $changed = true;
            }
            if (!$table->hasColumn('practice_questions')) {
                $table->addColumn('practice_questions', Types::SMALLINT, [
                    'notnull' => false,
                    'default' => 20,
                ]);
                $changed = true;
            }
            if (!$table->hasColumn('practice_minutes')) {
                $table->addColumn('practice_minutes', Types::SMALLINT, [
                    'notnull' => false,
                    'default' => 0,
                ]);
                $changed = true;
            }
            if (!$table->hasColumn('practice_pass_percent')) {
                $table->addColumn('practice_pass_percent', Types::SMALLINT, [
                    'notnull' => false,
                    'default' => 75,
                ]);
                $changed = true;
            }
        }

        if ($schema->hasTable('learning_sessions')) {
            $table = $schema->getTable('learning_sessions');
            if (!$table->hasColumn('exam_kind')) {
                $table->addColumn('exam_kind', Types::STRING, [
                    'notnull' => false,
                    'length' => 16,
                    'default' => null,
                ]);
                $changed = true;
            }
            if (!$table->hasColumn('pass_percent')) {
                $table->addColumn('pass_percent', Types::SMALLINT, [
                    'notnull' => false,
                    'default' => null,
                ]);
                $changed = true;
            }
        }

        return $changed ? $schema : null;
    }
}

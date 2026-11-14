<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * GitHub #47: the scope of a counting exam (question count and time limit) is set per pool in the
 * course's pool rules, with an optional course default as fallback.
 *
 * learning_course_pools
 *   exam_questions  questions per exam in this pool, NULL = course default
 *   exam_minutes    time limit in minutes, NULL = course default
 * learning_courses
 *   exam_questions  course default for every pool without its own value, NULL = none
 *   exam_minutes    course default time limit, NULL = none
 *
 * Both NULL everywhere = the exam behaves exactly as before (size from the pool, time from the
 * client within the server's bounds). Nullable columns without seed data, so changeSchema() alone
 * covers fresh installs and upgrades.
 */
class Version010700Date20261114200000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;
        foreach (['learning_course_pools', 'learning_courses'] as $tableName) {
            if (!$schema->hasTable($tableName)) {
                continue;
            }
            $table = $schema->getTable($tableName);
            foreach (['exam_questions', 'exam_minutes'] as $column) {
                if (!$table->hasColumn($column)) {
                    $table->addColumn($column, Types::INTEGER, ['notnull' => false, 'default' => null]);
                    $changed = true;
                }
            }
        }
        return $changed ? $schema : null;
    }
}

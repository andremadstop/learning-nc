<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * GitHub #66: rich feedback per question.
 *
 * learning_questions / learning_qst_translations:
 *   hint             shown on request before the learner answers (never during an exam)
 *   background_fact  shown after answering
 *   key_takeaway     shown after answering
 *   option_feedback  JSON [{position, why, misconception}] — feedback per answer option, keyed by
 *                    the answer's position (answer ids change on every save of the editor); the
 *                    verdict is the answer's is_correct and is not stored
 *
 * Nullable columns without seed data, so changeSchema() alone covers fresh installs and upgrades
 * (a fresh install runs only changeSchema(), see Version010100). No index.
 */
class Version010900Date20261116090000 extends SimpleMigrationStep {
    private const COLUMNS = ['hint', 'background_fact', 'key_takeaway', 'option_feedback'];

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;

        foreach (['learning_questions', 'learning_qst_translations'] as $tableName) {
            if (!$schema->hasTable($tableName)) {
                continue;
            }
            $table = $schema->getTable($tableName);
            foreach (self::COLUMNS as $column) {
                if ($table->hasColumn($column)) {
                    continue;
                }
                $table->addColumn($column, Types::TEXT, ['notnull' => false, 'default' => null]);
                $changed = true;
            }
        }

        return $changed ? $schema : null;
    }
}

<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Scenario / info text shown before a question (Codeberg #10).
 *
 * learning_questions.scenario      — optional reading text (case, situation, background)
 *                                    the learner sees above the question in every mode.
 * learning_qst_translations.scenario — its translation, next to text and explanation.
 *
 * In changeSchema(): a fresh install runs only that (see Version010100).
 */
class Version010400Date20261009120000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;

        foreach (['learning_questions', 'learning_qst_translations'] as $tableName) {
            if (!$schema->hasTable($tableName)) {
                continue;
            }
            $table = $schema->getTable($tableName);
            if ($table->hasColumn('scenario')) {
                continue;
            }
            $table->addColumn('scenario', Types::TEXT, ['notnull' => false]);
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}

<?php
declare(strict_types=1);
namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001300Date20260226000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('learning_leitner_items')) {
            $table = $schema->getTable('learning_leitner_items');

            // Composite index for box aggregations (XpService::updateUserStats, LeitnerService::getStats, ConsistencyCheckJob)
            if (!$table->hasIndex('learn_lt_user_box')) {
                $table->addIndex(['user_id', 'box'], 'learn_lt_user_box');
            }

            // Composite index for due-question queries (LeitnerService::getDueQuestions, NotificationJob)
            if (!$table->hasIndex('learn_lt_user_nextrev')) {
                $table->addIndex(['user_id', 'next_review'], 'learn_lt_user_nextrev');
            }
        }

        return $schema;
    }
}

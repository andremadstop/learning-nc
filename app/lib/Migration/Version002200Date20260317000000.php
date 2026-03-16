<?php
declare(strict_types=1);
namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add PBQ (Performance-Based Question) columns to learning_questions.
 *
 * pbq_subtype: identifies the PBQ variant (dropdown, placement, cli, cable)
 * pbq_config:  stores the full JSON config for the PBQ interaction
 */
class Version002200Date20260317000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('learning_questions')) {
            return null;
        }

        $table = $schema->getTable('learning_questions');

        if (!$table->hasColumn('pbq_subtype')) {
            $table->addColumn('pbq_subtype', 'string', [
                'notnull' => false,
                'length' => 50,
                'default' => null,
            ]);
            $output->info('Added pbq_subtype column to learning_questions');
        }

        if (!$table->hasColumn('pbq_config')) {
            $table->addColumn('pbq_config', 'text', [
                'notnull' => false,
                'default' => null,
            ]);
            $output->info('Added pbq_config column to learning_questions');
        }

        return $schema;
    }
}

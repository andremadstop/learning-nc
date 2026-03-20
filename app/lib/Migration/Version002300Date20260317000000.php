<?php
declare(strict_types=1);
namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version002300Date20260317000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('learning_questions')) { return null; }
        $table = $schema->getTable('learning_questions');

        if (!$table->hasColumn('instructor_note')) {
            $table->addColumn('instructor_note', 'text', [
                'notnull' => false,
                'default' => null,
            ]);
            $output->info('Added instructor_note column to learning_questions');
        }
        if (!$table->hasColumn('note_visible')) {
            $table->addColumn('note_visible', 'boolean', [
                'notnull' => false,
                'default' => false,
            ]);
            $output->info('Added note_visible column to learning_questions');
        }
        return $schema;
    }
}

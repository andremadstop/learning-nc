<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version002900Date20260318190000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        if ($schema->hasTable('learning_questions')) {
            $table = $schema->getTable('learning_questions');
            if (!$table->hasColumn('handbook_key')) {
                $table->addColumn('handbook_key', Types::STRING, ['notnull' => false, 'length' => 64]);
            }
            if (!$table->hasColumn('handbook_title')) {
                $table->addColumn('handbook_title', Types::STRING, ['notnull' => false, 'length' => 255]);
            }
            if (!$table->hasColumn('exam_key')) {
                $table->addColumn('exam_key', Types::STRING, ['notnull' => false, 'length' => 64]);
            }
            if (!$table->hasColumn('chapter_key')) {
                $table->addColumn('chapter_key', Types::STRING, ['notnull' => false, 'length' => 64]);
            }
            if (!$table->hasColumn('chapter_title')) {
                $table->addColumn('chapter_title', Types::STRING, ['notnull' => false, 'length' => 255]);
            }
            if (!$table->hasColumn('chapter_order')) {
                $table->addColumn('chapter_order', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
            }
            if (!$table->hasIndex('learn_q_exam_chapter_idx')) {
                $table->addIndex(['pool_id', 'exam_key', 'chapter_key', 'chapter_order'], 'learn_q_exam_chapter_idx');
            }
            if (!$table->hasIndex('learn_q_chapter_idx')) {
                $table->addIndex(['chapter_key', 'chapter_order'], 'learn_q_chapter_idx');
            }
        }

        return $schema;
    }
}

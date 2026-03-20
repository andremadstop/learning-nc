<?php
declare(strict_types=1);
namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version002500Date20260318000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('learning_duel_answers')) {
            $table = $schema->createTable('learning_duel_answers');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('duel_id', 'integer', ['notnull' => true]);
            $table->addColumn('question_index', 'integer', ['notnull' => true]);
            $table->addColumn('player_uid', 'string', ['length' => 64, 'notnull' => true]);
            $table->addColumn('answer_correct', 'boolean', ['notnull' => false, 'default' => false]);
            $table->addColumn('answered_at', 'integer', ['notnull' => true]);
            $table->addColumn('points_earned', 'integer', ['notnull' => true, 'default' => 0]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['duel_id', 'question_index'], 'lduel_ans_duel_q_idx');
            $output->info('Created learning_duel_answers table');
        }
        return $schema;
    }
}

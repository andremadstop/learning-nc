<?php
declare(strict_types=1);
namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version002400Date20260318000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if (!$schema->hasTable('learning_duel_sessions')) {
            $table = $schema->createTable('learning_duel_sessions');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('code', 'string', ['length' => 6, 'notnull' => true]);
            $table->addColumn('creator_uid', 'string', ['length' => 64, 'notnull' => true]);
            $table->addColumn('opponent_uid', 'string', ['length' => 64, 'notnull' => false, 'default' => null]);
            $table->addColumn('pool_id', 'integer', ['notnull' => true]);
            $table->addColumn('question_ids', 'text', ['notnull' => true]);
            $table->addColumn('status', 'string', ['length' => 20, 'notnull' => true, 'default' => 'waiting']);
            $table->addColumn('current_question_index', 'integer', ['notnull' => true, 'default' => 0]);
            $table->addColumn('creator_score', 'integer', ['notnull' => true, 'default' => 0]);
            $table->addColumn('opponent_score', 'integer', ['notnull' => true, 'default' => 0]);
            $table->addColumn('creator_ready', 'boolean', ['notnull' => false, 'default' => false]);
            $table->addColumn('opponent_ready', 'boolean', ['notnull' => false, 'default' => false]);
            $table->addColumn('creator_last_poll', 'integer', ['notnull' => false, 'default' => null]);
            $table->addColumn('opponent_last_poll', 'integer', ['notnull' => false, 'default' => null]);
            $table->addColumn('created_at', 'integer', ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['code'], 'lduel_sess_code_uidx');
            $output->info('Created learning_duel_sessions table');
        }
        return $schema;
    }
}

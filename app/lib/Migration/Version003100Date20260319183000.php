<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version003100Date20260319183000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        if (!$schema->hasTable('learning_support_tickets')) {
            $table = $schema->createTable('learning_support_tickets');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'open']);
            $table->addColumn('subject', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('message', Types::TEXT, ['notnull' => true]);
            $table->addColumn('context_json', Types::TEXT, ['notnull' => false]);
            $table->addColumn('course_id', Types::BIGINT, ['notnull' => false, 'length' => 8]);
            $table->addColumn('pool_id', Types::BIGINT, ['notnull' => false, 'length' => 8]);
            $table->addColumn('question_id', Types::BIGINT, ['notnull' => false, 'length' => 8]);
            $table->addColumn('duel_code', Types::STRING, ['notnull' => false, 'length' => 32]);
            $table->addColumn('league_season_id', Types::BIGINT, ['notnull' => false, 'length' => 8]);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 8]);
            $table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'length' => 8]);
            $table->addColumn('answered_by', Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->addColumn('answered_at', Types::BIGINT, ['notnull' => false, 'length' => 8]);
            $table->addColumn('answer_text', Types::TEXT, ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id', 'updated_at'], 'learn_ticket_user_idx');
            $table->addIndex(['status', 'updated_at'], 'learn_ticket_status_idx');
            $table->addIndex(['course_id', 'status'], 'learn_tkt_crs_status_idx');
        }

        return $schema;
    }
}

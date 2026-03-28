<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version003300Date20260319223000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        if (!$schema->hasTable('learning_duel_invites')) {
            $table = $schema->createTable('learning_duel_invites');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 8]);
            $table->addColumn('duel_session_id', Types::BIGINT, ['notnull' => true, 'length' => 8]);
            $table->addColumn('course_id', Types::BIGINT, ['notnull' => true, 'length' => 8]);
            $table->addColumn('pool_id', Types::BIGINT, ['notnull' => true, 'length' => 8]);
            $table->addColumn('inviter_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('invitee_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'open']);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 8]);
            $table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'length' => 8]);
            $table->addColumn('accepted_at', Types::BIGINT, ['notnull' => false, 'length' => 8]);
            $table->addColumn('responded_at', Types::BIGINT, ['notnull' => false, 'length' => 8]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['duel_session_id'], 'learn_dinv_session_uidx');
            $table->addIndex(['invitee_uid', 'status'], 'learn_dinv_invitee_idx');
            $table->addIndex(['inviter_uid', 'status'], 'learn_dinv_inviter_idx');
            $table->addIndex(['course_id', 'status'], 'learn_dinv_course_idx');
        }

        return $schema;
    }
}

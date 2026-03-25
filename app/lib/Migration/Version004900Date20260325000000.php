<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version004900Date20260325000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;

        if (!$schema->hasTable('learning_coop_sessions')) {
            $table = $schema->createTable('learning_coop_sessions');
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('campaign_id', Types::STRING, ['notnull' => true, 'length' => 191]);
            $table->addColumn('host_user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('session_code', Types::STRING, ['notnull' => true, 'length' => 8]);
            $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'lobby']);
            $table->addColumn('current_node_id', Types::STRING, ['notnull' => true, 'length' => 191]);
            $table->addColumn('state_bag', Types::TEXT, ['notnull' => true]);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('last_heartbeat', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('max_players', Types::INTEGER, ['notnull' => true, 'default' => 4]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['session_code'], 'lcoop_session_code_uidx');
            $table->addIndex(['campaign_id', 'status'], 'lcoop_campaign_status_idx');
            $table->addIndex(['host_user_id'], 'lcoop_host_idx');
            $changed = true;
        }

        if (!$schema->hasTable('learning_coop_players')) {
            $table = $schema->createTable('learning_coop_players');
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('session_id', Types::INTEGER, ['notnull' => true]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('display_name', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('character_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('is_ready', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->addColumn('is_host', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->addColumn('joined_at', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('last_heartbeat', Types::BIGINT, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['session_id', 'user_id'], 'lcoop_player_session_user_uidx');
            $table->addIndex(['session_id'], 'lcoop_player_session_idx');
            $table->addIndex(['user_id'], 'lcoop_player_user_idx');
            $changed = true;
        }

        if (!$schema->hasTable('learning_coop_votes')) {
            $table = $schema->createTable('learning_coop_votes');
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('session_id', Types::INTEGER, ['notnull' => true]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('node_id', Types::STRING, ['notnull' => true, 'length' => 191]);
            $table->addColumn('edge_id', Types::STRING, ['notnull' => true, 'length' => 191]);
            $table->addColumn('voted_at', Types::BIGINT, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['session_id', 'user_id', 'node_id'], 'lcoop_vote_session_user_node_uidx');
            $table->addIndex(['session_id', 'node_id'], 'lcoop_vote_session_node_idx');
            $changed = true;
        }

        if ($schema->hasTable('learning_coop_players') && $schema->hasTable('learning_coop_sessions')) {
            $table = $schema->getTable('learning_coop_players');
            if (!$table->hasForeignKey('lcoop_player_session_fk')) {
                $table->addForeignKeyConstraint(
                    $schema->getTable('learning_coop_sessions'),
                    ['session_id'],
                    ['id'],
                    ['onDelete' => 'CASCADE'],
                    'lcoop_player_session_fk'
                );
                $changed = true;
            }
        }

        if ($schema->hasTable('learning_coop_votes') && $schema->hasTable('learning_coop_sessions')) {
            $table = $schema->getTable('learning_coop_votes');
            if (!$table->hasForeignKey('lcoop_vote_session_fk')) {
                $table->addForeignKeyConstraint(
                    $schema->getTable('learning_coop_sessions'),
                    ['session_id'],
                    ['id'],
                    ['onDelete' => 'CASCADE'],
                    'lcoop_vote_session_fk'
                );
                $changed = true;
            }
        }

        return $changed ? $schema : null;
    }
}

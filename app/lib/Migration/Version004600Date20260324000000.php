<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Campaign Engine v2 — Phase 71
 *
 * Creates learning_campaign_state table for tracking per-user
 * graph-based campaign progress with state-bag (flags, items, reputation).
 */
class Version004600Date20260324000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('learning_campaign_state')) {
            return $schema;
        }

        $table = $schema->createTable('learning_campaign_state');

        $table->addColumn('id', Types::INTEGER, [
            'autoincrement' => true,
            'notnull' => true,
        ]);
        $table->addColumn('user_id', Types::STRING, [
            'length' => 64,
            'notnull' => true,
        ]);
        $table->addColumn('campaign_id', Types::STRING, [
            'length' => 128,
            'notnull' => true,
        ]);
        $table->addColumn('graph_position', Types::STRING, [
            'length' => 128,
            'notnull' => true,
        ]);
        $table->addColumn('state_bag', Types::TEXT, [
            'notnull' => true,
            'default' => '{}',
        ]);
        $table->addColumn('act_number', Types::INTEGER, [
            'notnull' => true,
            'default' => 1,
        ]);
        $table->addColumn('status', Types::STRING, [
            'length' => 32,
            'notnull' => true,
            'default' => 'in_progress',
        ]);
        $table->addColumn('character_class', Types::STRING, [
            'length' => 32,
            'notnull' => true,
            'default' => 'helpdesk',
        ]);
        $table->addColumn('choices_json', Types::TEXT, [
            'notnull' => false,
            'default' => null,
        ]);
        $table->addColumn('score', Types::INTEGER, [
            'notnull' => true,
            'default' => 0,
        ]);
        $table->addColumn('created_at', Types::BIGINT, [
            'notnull' => true,
        ]);
        $table->addColumn('updated_at', Types::BIGINT, [
            'notnull' => true,
        ]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['user_id', 'campaign_id'], 'lrn_campstate_user_camp_idx');
        $table->addIndex(['campaign_id'], 'lrn_campstate_campaign_idx');

        return $schema;
    }
}

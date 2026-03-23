<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Hack Through Time — Phase 48
 *
 * Creates learning_epoch_progress table for tracking per-user
 * epoch progress in the Zeitreise game mode.
 */
class Version004500Date20260323000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('learning_epoch_progress')) {
            return $schema;
        }

        $table = $schema->createTable('learning_epoch_progress');

        $table->addColumn('id', Types::INTEGER, [
            'autoincrement' => true,
            'notnull' => true,
        ]);
        $table->addColumn('user_id', Types::STRING, [
            'length' => 64,
            'notnull' => true,
        ]);
        $table->addColumn('epoch_id', Types::STRING, [
            'length' => 64,
            'notnull' => true,
        ]);
        $table->addColumn('character_class', Types::STRING, [
            'length' => 32,
            'notnull' => true,
        ]);
        $table->addColumn('current_scene', Types::STRING, [
            'length' => 128,
            'notnull' => false,
            'default' => null,
        ]);
        $table->addColumn('score', Types::INTEGER, [
            'notnull' => true,
            'default' => 0,
        ]);
        $table->addColumn('total_questions', Types::INTEGER, [
            'notnull' => true,
            'default' => 0,
        ]);
        $table->addColumn('correct_answers', Types::INTEGER, [
            'notnull' => true,
            'default' => 0,
        ]);
        $table->addColumn('status', Types::STRING, [
            'length' => 16,
            'notnull' => true,
            'default' => 'not_started',
        ]);
        $table->addColumn('museum_viewed', Types::TEXT, [
            'notnull' => false,
            'default' => null,
        ]);
        $table->addColumn('created_at', Types::BIGINT, [
            'notnull' => true,
        ]);
        $table->addColumn('updated_at', Types::BIGINT, [
            'notnull' => true,
        ]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['user_id', 'epoch_id'], 'lrn_epoch_user_epoch_idx');
        $table->addIndex(['user_id', 'status'], 'lrn_epoch_user_status_idx');

        return $schema;
    }
}

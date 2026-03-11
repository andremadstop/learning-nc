<?php
declare(strict_types=1);
namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version002000Date20260311193000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('learning_user_stats')) {
            $table = $schema->getTable('learning_user_stats');
            if (!$table->hasColumn('streak_freeze_tokens')) {
                $table->addColumn('streak_freeze_tokens', Types::INTEGER, [
                    'notnull' => true,
                    'default' => 1,
                    'unsigned' => true,
                ]);
            }
            if (!$table->hasColumn('last_freeze_reset_week')) {
                $table->addColumn('last_freeze_reset_week', Types::STRING, [
                    'notnull' => false,
                    'length' => 8,
                ]);
            }
        }

        if (!$schema->hasTable('learning_user_mission_claims')) {
            $table = $schema->createTable('learning_user_mission_claims');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('mission_key', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('mission_date', Types::STRING, ['notnull' => true, 'length' => 10]);
            $table->addColumn('xp_earned', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
            $table->addColumn('claimed_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['user_id', 'mission_key', 'mission_date'], 'learn_mission_claim_uniq');
            $table->addIndex(['user_id', 'mission_date'], 'learn_mission_user_day_idx');
        }

        return $schema;
    }
}


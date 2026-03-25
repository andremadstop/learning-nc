<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Phase 75: Create user_telos table for personal learning profiles.
 */
class Version004800Date20260324020000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('learning_user_telos')) {
            $table = $schema->createTable('learning_user_telos');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('telos_json', Types::TEXT, [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('bio', Types::TEXT, [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('help_offer', Types::TEXT, [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('help_wanted', Types::TEXT, [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('visibility', Types::STRING, [
                'notnull' => true,
                'length' => 16,
                'default' => 'private',
            ]);
            $table->addColumn('onboarding_completed', Types::SMALLINT, [
                'notnull' => true,
                'default' => 0,
                'unsigned' => true,
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('updated_at', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['user_id'], 'lnc_telos_user_idx');
        }

        return $schema;
    }
}

<?php
declare(strict_types=1);
namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001100Date20260225000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('learning_user_badges')) {
            $table = $schema->createTable('learning_user_badges');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('badge_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('earned_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('pool_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['user_id', 'badge_id'], 'learn_badge_user_uniq');
            $table->addIndex(['user_id'], 'learn_badge_user_idx');
        }

        return $schema;
    }
}

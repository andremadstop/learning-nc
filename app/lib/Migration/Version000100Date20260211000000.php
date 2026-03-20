<?php
declare(strict_types=1);
namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000100Date20260211000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        if (!$schema->hasTable('learning_pools')) {
            $table = $schema->createTable('learning_pools');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('description', Types::TEXT, ['notnull' => false]);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'learning_pools_user_id');
        }

        return $schema;
    }
}

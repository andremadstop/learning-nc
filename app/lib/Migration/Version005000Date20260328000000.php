<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version005000Date20260328000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('learning_feed_items')) {
            return null;
        }

        $table = $schema->createTable('learning_feed_items');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
        $table->addColumn('course_id', Types::INTEGER, ['notnull' => true]);
        $table->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 32]);
        $table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
        $table->addColumn('body', Types::TEXT, ['notnull' => false]);
        $table->addColumn('actor_id', Types::STRING, ['notnull' => false, 'length' => 64]);
        $table->addColumn('meta', Types::TEXT, ['notnull' => false]);
        $table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['course_id'], 'lfeed_course_idx');
        $table->addIndex(['course_id', 'created_at'], 'lfeed_course_created_idx');
        $table->addIndex(['type'], 'lfeed_type_idx');

        return $schema;
    }
}

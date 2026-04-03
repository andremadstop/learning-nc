<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version007200Date20260403000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('learning_kudos')) {
            return $schema;
        }

        $table = $schema->createTable('learning_kudos');
        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull' => true,
            'unsigned' => true,
        ]);
        $table->addColumn('course_id', Types::BIGINT, [
            'notnull' => true,
            'unsigned' => true,
        ]);
        $table->addColumn('from_user', Types::STRING, [
            'notnull' => true,
            'length' => 64,
        ]);
        $table->addColumn('to_user', Types::STRING, [
            'notnull' => true,
            'length' => 64,
        ]);
        $table->addColumn('message', Types::TEXT, [
            'notnull' => false,
            'default' => null,
        ]);
        $table->addColumn('created_at', Types::BIGINT, [
            'notnull' => true,
            'unsigned' => true,
        ]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['course_id', 'to_user'], 'lnc_kudos_to_idx');
        $table->addIndex(['course_id', 'from_user', 'created_at'], 'lnc_kudos_from_idx');

        return $schema;
    }
}

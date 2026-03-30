<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version006200Date20260330000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('learning_user_telos')) {
            return $schema;
        }

        $table = $schema->getTable('learning_user_telos');

        if (!$table->hasColumn('ics_token')) {
            $table->addColumn('ics_token', Types::STRING, [
                'notnull' => false,
                'length' => 64,
                'default' => null,
            ]);
        }

        if (!$table->hasIndex('lnc_telos_ics_token_idx')) {
            $table->addUniqueIndex(['ics_token'], 'lnc_telos_ics_token_idx');
        }

        return $schema;
    }
}

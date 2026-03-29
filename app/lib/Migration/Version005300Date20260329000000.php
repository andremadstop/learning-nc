<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Phase 102: Add ai_consent_version column to user_telos for versioned AI consent.
 */
class Version005300Date20260329000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('learning_user_telos')) {
            $table = $schema->getTable('learning_user_telos');

            if (!$table->hasColumn('ai_consent_version')) {
                $table->addColumn('ai_consent_version', Types::STRING, [
                    'notnull' => false,
                    'length' => 20,
                    'default' => null,
                ]);
            }
        }

        return $schema;
    }
}

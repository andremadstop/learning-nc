<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Sprint Mode — add question_started_at to gameshow_sessions.
 *
 * Tracks when each question's countdown timer began (ms epoch).
 */
class Version003800Date20260320120000 extends SimpleMigrationStep {

    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('learning_gameshow_sessions')) {
            $table = $schema->getTable('learning_gameshow_sessions');
            if (!$table->hasColumn('question_started_at')) {
                $table->addColumn('question_started_at', Types::BIGINT, [
                    'notnull' => false,
                    'default' => null,
                ]);
                return $schema;
            }
        }

        return null;
    }
}

<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Schwarmgedaechtnis: Add user_id, status, source_type to rag_chunks.
 */
class Version005200Date20260329000000 extends SimpleMigrationStep {
    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('learning_rag_chunks')) {
            $table = $schema->getTable('learning_rag_chunks');

            if (!$table->hasColumn('user_id')) {
                $table->addColumn('user_id', Types::STRING, [
                    'notnull' => false,
                    'length' => 64,
                    'default' => null,
                ]);
            }

            if (!$table->hasColumn('status')) {
                $table->addColumn('status', Types::STRING, [
                    'notnull' => true,
                    'length' => 16,
                    'default' => 'approved',
                ]);
            }

            if (!$table->hasColumn('source_type')) {
                $table->addColumn('source_type', Types::STRING, [
                    'notnull' => true,
                    'length' => 16,
                    'default' => 'vault',
                ]);
            }

            if (!$table->hasIndex('lrn_ragchunk_course_status_idx')) {
                $table->addIndex(['course_id', 'status'], 'lrn_ragchunk_course_status_idx');
            }

            if (!$table->hasIndex('lrn_ragchunk_user_idx')) {
                $table->addIndex(['user_id'], 'lrn_ragchunk_user_idx');
            }
        }

        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        // Backfill source_type based on document_id convention
        $this->db->executeStatement(
            "UPDATE *PREFIX*learning_rag_chunks SET source_type = 'web' WHERE document_id = -1 AND source_type = 'vault'"
        );
        $this->db->executeStatement(
            "UPDATE *PREFIX*learning_rag_chunks SET source_type = 'document' WHERE document_id > 0 AND source_type = 'vault'"
        );
    }
}

<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Chunking Pipeline — Phase 37
 *
 * Creates learning_rag_chunks table for storing document text chunks
 * used by keyword search and future RAG pipeline.
 *
 * Also adds chunking_status column to learning_course_documents for
 * tracking the chunking state of each document.
 */
class Version004400Date20260322000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // --- Add chunking_status to existing course_documents table ---
        if ($schema->hasTable('learning_course_documents')) {
            $docsTable = $schema->getTable('learning_course_documents');
            if (!$docsTable->hasColumn('chunking_status')) {
                $docsTable->addColumn('chunking_status', Types::STRING, [
                    'length' => 20,
                    'notnull' => false,
                    'default' => 'pending',
                ]);
            }
        }

        // --- Create rag_chunks table ---
        if ($schema->hasTable('learning_rag_chunks')) {
            return $schema;
        }

        $table = $schema->createTable('learning_rag_chunks');

        $table->addColumn('id', Types::INTEGER, [
            'autoincrement' => true,
            'notnull' => true,
        ]);
        $table->addColumn('document_id', Types::INTEGER, [
            'notnull' => true,
        ]);
        $table->addColumn('course_id', Types::INTEGER, [
            'notnull' => true,
        ]);
        $table->addColumn('chapter', Types::STRING, [
            'length' => 500,
            'notnull' => false,
            'default' => null,
        ]);
        $table->addColumn('text', Types::TEXT, [
            'notnull' => true,
        ]);
        $table->addColumn('source_file', Types::STRING, [
            'length' => 500,
            'notnull' => true,
        ]);
        $table->addColumn('chunk_index', Types::INTEGER, [
            'notnull' => true,
        ]);
        $table->addColumn('token_count', Types::INTEGER, [
            'notnull' => true,
        ]);
        $table->addColumn('created_at', Types::INTEGER, [
            'notnull' => true,
        ]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['course_id'], 'lrn_ragchunk_course_idx');
        $table->addIndex(['document_id'], 'lrn_ragchunk_doc_idx');

        return $schema;
    }
}

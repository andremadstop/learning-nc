<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Document Upload & Extraction — Phase 36
 *
 * Creates learning_course_documents table for tracking uploaded course materials
 * and their text extraction status (PDF via pdftotext, Markdown direct).
 *
 * Also adds material_folder column to learning_courses for linking an NC folder.
 */
class Version004300Date20260322000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // --- Add material_folder to existing courses table ---
        if ($schema->hasTable('learning_courses')) {
            $coursesTable = $schema->getTable('learning_courses');
            if (!$coursesTable->hasColumn('material_folder')) {
                $coursesTable->addColumn('material_folder', Types::STRING, [
                    'length' => 1024,
                    'notnull' => false,
                    'default' => null,
                ]);
            }
        }

        // --- Create course_documents table ---
        if ($schema->hasTable('learning_course_documents')) {
            return $schema;
        }

        $table = $schema->createTable('learning_course_documents');

        $table->addColumn('id', Types::INTEGER, [
            'autoincrement' => true,
            'notnull' => true,
        ]);
        $table->addColumn('course_id', Types::INTEGER, [
            'notnull' => true,
        ]);
        $table->addColumn('file_path', Types::STRING, [
            'length' => 1024,
            'notnull' => true,
        ]);
        $table->addColumn('file_name', Types::STRING, [
            'length' => 255,
            'notnull' => true,
        ]);
        $table->addColumn('file_type', Types::STRING, [
            'length' => 20,
            'notnull' => true,
        ]);
        $table->addColumn('status', Types::STRING, [
            'length' => 20,
            'notnull' => true,
            'default' => 'uploaded',
        ]);
        $table->addColumn('extracted_text', Types::TEXT, [
            'notnull' => false,
            'default' => null,
        ]);
        $table->addColumn('error_message', Types::STRING, [
            'length' => 500,
            'notnull' => false,
            'default' => null,
        ]);
        $table->addColumn('file_size', Types::INTEGER, [
            'notnull' => false,
            'default' => null,
        ]);
        $table->addColumn('created_at', Types::INTEGER, [
            'notnull' => true,
        ]);
        $table->addColumn('updated_at', Types::INTEGER, [
            'notnull' => true,
        ]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['course_id'], 'lrn_doc_course_idx');

        return $schema;
    }
}

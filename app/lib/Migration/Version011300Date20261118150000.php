<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * GitHub #86: "Confirm I have read this" only after the learner opened the document.
 *
 * learning_doc_opens — the last time a learner opened a required document of a course through
 *   document#download, with the version of the file that was served (DocumentService::fileVersion()).
 *   One row per (user, course, document): a later open overwrites the earlier one, because the
 *   download always serves the current file and only the newest open is ever asked for
 *   (RequiredDocumentService::confirm()). No evidence, not in the compliance chain; deleted by the
 *   retention job, with the user, with the course and on uninstall.
 * learning_courses.open_before_confirm — the course asks for that open (mandatory-training
 *   profile: on). NULL/false = confirm without opening, as before 5.16.0.
 *
 * A table and a nullable column without data to fill: changeSchema() alone covers fresh installs
 * and upgrades. Existing confirmations are not touched and stay valid.
 */
class Version011300Date20261118150000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;

        if (!$schema->hasTable('learning_doc_opens')) {
            $table = $schema->createTable('learning_doc_opens');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('course_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('document_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('doc_version', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('opened_at', Types::BIGINT, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['user_id', 'course_id', 'document_id'], 'learn_dopen_usr_crs_doc_uq');
            $table->addIndex(['course_id'], 'learn_dopen_course_idx');
            $table->addIndex(['opened_at'], 'learn_dopen_opened_idx');
            $changed = true;
        }

        if ($schema->hasTable('learning_courses')) {
            $table = $schema->getTable('learning_courses');
            if (!$table->hasColumn('open_before_confirm')) {
                $table->addColumn('open_before_confirm', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
                $changed = true;
            }
        }

        return $changed ? $schema : null;
    }
}

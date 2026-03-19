<?php
declare(strict_types=1);
namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Rename translation tables to comply with Nextcloud's 27-char table name limit.
 *
 * learning_question_translations (30 chars) → learning_qst_translations (25 chars)
 * learning_answer_translations   (28 chars) → learning_ans_translations (25 chars)
 *
 * Existing installations have the old names; fresh installs (CI) already get
 * the new names from Version000350. This migration is a no-op on fresh installs.
 */
class Version002100Date20260316000000 extends SimpleMigrationStep {

    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $prefix = method_exists($this->db, 'getPrefix') ? $this->db->getPrefix() : 'oc_';

        if ($schema->hasTable('learning_question_translations') && !$schema->hasTable('learning_qst_translations')) {
            $this->db->executeStatement(
                "ALTER TABLE {$prefix}learning_question_translations RENAME TO {$prefix}learning_qst_translations"
            );
            $output->info('Renamed learning_question_translations → learning_qst_translations');
        }

        if ($schema->hasTable('learning_answer_translations') && !$schema->hasTable('learning_ans_translations')) {
            $this->db->executeStatement(
                "ALTER TABLE {$prefix}learning_answer_translations RENAME TO {$prefix}learning_ans_translations"
            );
            $output->info('Renamed learning_answer_translations → learning_ans_translations');
        }

        if ($schema->hasTable('learning_user_mission_claims') && !$schema->hasTable('learning_mission_claims')) {
            $this->db->executeStatement(
                "ALTER TABLE {$prefix}learning_user_mission_claims RENAME TO {$prefix}learning_mission_claims"
            );
            $output->info('Renamed learning_user_mission_claims → learning_mission_claims');
        }
    }
}

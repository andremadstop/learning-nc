<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version003200Date20260319193000 extends SimpleMigrationStep {

    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $prefix = $this->db->getTablePrefix();
        $allowed = "ARRAY['de'::character varying, 'en'::character varying, 'ru'::character varying, 'ar'::character varying]";

        if ($schema->hasTable('learning_qst_translations')) {
            $this->db->executeStatement("ALTER TABLE {$prefix}learning_qst_translations DROP CONSTRAINT IF EXISTS {$prefix}learning_question_translations_lang_check");
            $this->db->executeStatement("ALTER TABLE {$prefix}learning_qst_translations DROP CONSTRAINT IF EXISTS {$prefix}learning_qst_translations_lang_check");
            $this->db->executeStatement(
                "ALTER TABLE {$prefix}learning_qst_translations ADD CONSTRAINT {$prefix}learning_qst_translations_lang_check CHECK ((lang)::text = ANY (({$allowed})::text[]))"
            );
            $output->info('Updated language check constraint on learning_qst_translations');
        }

        if ($schema->hasTable('learning_ans_translations')) {
            $this->db->executeStatement("ALTER TABLE {$prefix}learning_ans_translations DROP CONSTRAINT IF EXISTS {$prefix}learning_answer_translations_lang_check");
            $this->db->executeStatement("ALTER TABLE {$prefix}learning_ans_translations DROP CONSTRAINT IF EXISTS {$prefix}learning_ans_translations_lang_check");
            $this->db->executeStatement(
                "ALTER TABLE {$prefix}learning_ans_translations ADD CONSTRAINT {$prefix}learning_ans_translations_lang_check CHECK ((lang)::text = ANY (({$allowed})::text[]))"
            );
            $output->info('Updated language check constraint on learning_ans_translations');
        }
    }
}

<?php
declare(strict_types=1);
namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Issue #9: Sync DB table names with what the Mapper classes have been
 * referring to since v3.5.0 (commit 7016ddd, 2026-03-29).
 *
 * That commit renamed the table identifiers in the Mapper classes:
 *   learning_q_translations -> learning_qst_translations
 *   learning_a_translations -> learning_ans_translations
 * but never shipped a migration to rename the tables themselves. So
 * every fresh install since v3.5.0 ended up with the original names
 * from Version000350 in the database, while the code looked for the
 * renamed identifiers — producing SQLSTATE[42S02] / 1146 once a Mapper
 * touched the translations.
 *
 * v4.4.1 attempted a simple `ALTER TABLE RENAME` but assumed the legacy
 * table existed. On installs where Version000350 had silently failed
 * (no legacy table either) the rename crashed with 1146, blocking the
 * upgrade entirely. v4.4.2 makes the migration self-healing across all
 * three states:
 *
 *   1. New table already exists       -> no-op
 *   2. Old table exists, new doesn't  -> ALTER TABLE RENAME
 *   3. Neither exists                 -> CREATE TABLE (recovers from
 *                                        a historical Version000350 fail)
 *
 * Existence is checked against the real DB via information_schema, not
 * against the Doctrine schema cache, because the two can disagree on
 * installs that have skipped or failed earlier migrations.
 */
class Version007900Date20260430000000 extends SimpleMigrationStep {

    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void {
        $prefix = method_exists($this->db, 'getPrefix') ? $this->db->getPrefix() : 'oc_';

        $this->ensureTable(
            $prefix,
            'learning_q_translations',
            'learning_qst_translations',
            'question_id',
            'learn_qt_question_idx',
            'learn_qt_q_lang_uniq',
            true,
            $output
        );

        $this->ensureTable(
            $prefix,
            'learning_a_translations',
            'learning_ans_translations',
            'answer_id',
            'learn_at_answer_idx',
            'learn_at_a_lang_uniq',
            false,
            $output
        );
    }

    private function ensureTable(
        string $prefix,
        string $oldName,
        string $newName,
        string $foreignKeyColumn,
        string $foreignKeyIndex,
        string $uniqueIndex,
        bool $hasExplanation,
        IOutput $output
    ): void {
        $oldFull = $prefix . $oldName;
        $newFull = $prefix . $newName;

        if ($this->tableExists($newFull)) {
            return;
        }

        if ($this->tableExists($oldFull)) {
            try {
                $this->db->executeStatement("ALTER TABLE {$oldFull} RENAME TO {$newFull}");
                $output->info("Renamed {$oldName} -> {$newName} (issue #9)");
                return;
            } catch (\Throwable $e) {
                $output->warning("Rename {$oldName} -> {$newName} failed: " . $e->getMessage() . " — attempting create-from-scratch");
            }
        }

        // Fallback: create the table from scratch. Recovers from installs
        // where Version000350 historically failed and left no legacy table.
        $explanationCol = $hasExplanation ? "`explanation` TEXT NULL," : '';
        $sql = "
            CREATE TABLE {$newFull} (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `{$foreignKeyColumn}` BIGINT UNSIGNED NOT NULL,
                `lang` VARCHAR(10) NOT NULL,
                `text` LONGTEXT NOT NULL,
                {$explanationCol}
                `created_at` BIGINT UNSIGNED NULL,
                PRIMARY KEY (`id`),
                INDEX `{$foreignKeyIndex}` (`{$foreignKeyColumn}`),
                UNIQUE INDEX `{$uniqueIndex}` (`{$foreignKeyColumn}`, `lang`)
            )
        ";

        $platform = $this->db->getDatabasePlatform()->getName();
        if ($platform === 'postgresql') {
            $explanationColPg = $hasExplanation ? '"explanation" TEXT NULL,' : '';
            $sql = "
                CREATE TABLE \"{$newFull}\" (
                    \"id\" BIGSERIAL PRIMARY KEY,
                    \"{$foreignKeyColumn}\" BIGINT NOT NULL,
                    \"lang\" VARCHAR(10) NOT NULL,
                    \"text\" TEXT NOT NULL,
                    {$explanationColPg}
                    \"created_at\" BIGINT NULL
                )
            ";
            $this->db->executeStatement($sql);
            $this->db->executeStatement("CREATE INDEX \"{$foreignKeyIndex}\" ON \"{$newFull}\" (\"{$foreignKeyColumn}\")");
            $this->db->executeStatement("CREATE UNIQUE INDEX \"{$uniqueIndex}\" ON \"{$newFull}\" (\"{$foreignKeyColumn}\", \"lang\")");
        } elseif ($platform === 'sqlite') {
            $explanationColSq = $hasExplanation ? '"explanation" TEXT,' : '';
            $sql = "
                CREATE TABLE \"{$newFull}\" (
                    \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,
                    \"{$foreignKeyColumn}\" INTEGER NOT NULL,
                    \"lang\" TEXT NOT NULL,
                    \"text\" TEXT NOT NULL,
                    {$explanationColSq}
                    \"created_at\" INTEGER
                )
            ";
            $this->db->executeStatement($sql);
            $this->db->executeStatement("CREATE INDEX \"{$foreignKeyIndex}\" ON \"{$newFull}\" (\"{$foreignKeyColumn}\")");
            $this->db->executeStatement("CREATE UNIQUE INDEX \"{$uniqueIndex}\" ON \"{$newFull}\" (\"{$foreignKeyColumn}\", \"lang\")");
        } else {
            $this->db->executeStatement($sql);
        }

        $output->info("Created {$newName} from scratch (issue #9 recovery — neither legacy nor new table existed)");
    }

    private function tableExists(string $fullyQualifiedName): bool {
        $platform = $this->db->getDatabasePlatform()->getName();
        try {
            if ($platform === 'sqlite') {
                $sql = "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?";
            } elseif ($platform === 'postgresql') {
                $sql = "SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?";
            } else {
                $sql = "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?";
            }
            $result = $this->db->executeQuery($sql, [$fullyQualifiedName]);
            $exists = (bool)$result->fetchOne();
            $result->closeCursor();
            return $exists;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

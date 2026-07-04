<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Repair course feature table-name drift from Version003400/003500.
 *
 * Older migrations created shorter table names, while CourseService/Mapper code
 * reads and writes the learning_course_* names. This migration preserves data by
 * renaming legacy tables when present, and creates the target table only on
 * inconsistent installs where neither table exists.
 */
class Version009900Date20260703180000 extends SimpleMigrationStep {
    public function __construct(
        private readonly IDBConnection $db,
    ) {}

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $prefix = method_exists($this->db, 'getPrefix') ? $this->db->getPrefix() : 'oc_';

        $this->ensureCurriculumScopes($prefix, $output);
        $this->ensureQuestionOverrides($prefix, $output);
        $this->ensureCourseAnnouncements($prefix, $output);
    }

    private function ensureCurriculumScopes(string $prefix, IOutput $output): void {
        $this->ensureTable(
            $prefix,
            'learning_curriculum_scopes',
            'learning_course_curriculum_scopes',
            function (string $table, string $platform): void {
                if ($platform === 'postgresql') {
                    $this->db->executeStatement("
                        CREATE TABLE \"{$table}\" (
                            \"id\" BIGSERIAL PRIMARY KEY,
                            \"course_id\" BIGINT NOT NULL,
                            \"enabled\" BOOLEAN DEFAULT false,
                            \"handbook_key\" VARCHAR(128) NULL,
                            \"handbook_title\" VARCHAR(256) NULL,
                            \"chapter_keys_json\" TEXT NULL,
                            \"created_at\" BIGINT NOT NULL,
                            \"updated_at\" BIGINT NOT NULL
                        )
                    ");
                    $this->db->executeStatement("CREATE UNIQUE INDEX \"curriculum_scope_course_unique\" ON \"{$table}\" (\"course_id\")");
                    return;
                }

                if ($platform === 'sqlite') {
                    $this->db->executeStatement("
                        CREATE TABLE \"{$table}\" (
                            \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,
                            \"course_id\" INTEGER NOT NULL,
                            \"enabled\" BOOLEAN DEFAULT 0,
                            \"handbook_key\" VARCHAR(128) NULL,
                            \"handbook_title\" VARCHAR(256) NULL,
                            \"chapter_keys_json\" TEXT NULL,
                            \"created_at\" INTEGER NOT NULL,
                            \"updated_at\" INTEGER NOT NULL
                        )
                    ");
                    $this->db->executeStatement("CREATE UNIQUE INDEX \"curriculum_scope_course_unique\" ON \"{$table}\" (\"course_id\")");
                    return;
                }

                $this->db->executeStatement("
                    CREATE TABLE {$table} (
                        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                        `course_id` BIGINT UNSIGNED NOT NULL,
                        `enabled` TINYINT(1) DEFAULT 0,
                        `handbook_key` VARCHAR(128) NULL,
                        `handbook_title` VARCHAR(256) NULL,
                        `chapter_keys_json` LONGTEXT NULL,
                        `created_at` BIGINT UNSIGNED NOT NULL,
                        `updated_at` BIGINT UNSIGNED NOT NULL,
                        PRIMARY KEY (`id`),
                        UNIQUE INDEX `curriculum_scope_course_unique` (`course_id`)
                    )
                ");
            },
            $output
        );
    }

    private function ensureQuestionOverrides(string $prefix, IOutput $output): void {
        $this->ensureTable(
            $prefix,
            'learning_q_overrides',
            'learning_course_question_overrides',
            function (string $table, string $platform): void {
                if ($platform === 'postgresql') {
                    $this->db->executeStatement("
                        CREATE TABLE \"{$table}\" (
                            \"id\" BIGSERIAL PRIMARY KEY,
                            \"course_id\" BIGINT NOT NULL,
                            \"question_id\" BIGINT NOT NULL,
                            \"paused\" BOOLEAN DEFAULT false,
                            \"highlight\" BOOLEAN DEFAULT false,
                            \"created_at\" BIGINT NULL,
                            \"updated_at\" BIGINT NULL
                        )
                    ");
                    $this->db->executeStatement("CREATE UNIQUE INDEX \"cqo_course_question_unique\" ON \"{$table}\" (\"course_id\", \"question_id\")");
                    return;
                }

                if ($platform === 'sqlite') {
                    $this->db->executeStatement("
                        CREATE TABLE \"{$table}\" (
                            \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,
                            \"course_id\" INTEGER NOT NULL,
                            \"question_id\" INTEGER NOT NULL,
                            \"paused\" BOOLEAN DEFAULT 0,
                            \"highlight\" BOOLEAN DEFAULT 0,
                            \"created_at\" INTEGER NULL,
                            \"updated_at\" INTEGER NULL
                        )
                    ");
                    $this->db->executeStatement("CREATE UNIQUE INDEX \"cqo_course_question_unique\" ON \"{$table}\" (\"course_id\", \"question_id\")");
                    return;
                }

                $this->db->executeStatement("
                    CREATE TABLE {$table} (
                        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                        `course_id` BIGINT UNSIGNED NOT NULL,
                        `question_id` BIGINT UNSIGNED NOT NULL,
                        `paused` TINYINT(1) DEFAULT 0,
                        `highlight` TINYINT(1) DEFAULT 0,
                        `created_at` BIGINT UNSIGNED NULL,
                        `updated_at` BIGINT UNSIGNED NULL,
                        PRIMARY KEY (`id`),
                        UNIQUE INDEX `cqo_course_question_unique` (`course_id`, `question_id`)
                    )
                ");
            },
            $output
        );
    }

    private function ensureCourseAnnouncements(string $prefix, IOutput $output): void {
        $this->ensureTable(
            $prefix,
            'learning_announcements',
            'learning_course_announcements',
            function (string $table, string $platform): void {
                if ($platform === 'postgresql') {
                    $this->db->executeStatement("
                        CREATE TABLE \"{$table}\" (
                            \"id\" BIGSERIAL PRIMARY KEY,
                            \"course_id\" BIGINT NOT NULL,
                            \"instructor_id\" VARCHAR(64) NULL,
                            \"title\" VARCHAR(255) NOT NULL DEFAULT '',
                            \"body\" TEXT NULL,
                            \"created_at\" BIGINT NULL,
                            \"expires_at\" BIGINT NULL
                        )
                    ");
                    $this->db->executeStatement("CREATE INDEX \"ca_course_id_idx\" ON \"{$table}\" (\"course_id\")");
                    return;
                }

                if ($platform === 'sqlite') {
                    $this->db->executeStatement("
                        CREATE TABLE \"{$table}\" (
                            \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,
                            \"course_id\" INTEGER NOT NULL,
                            \"instructor_id\" VARCHAR(64) NULL,
                            \"title\" VARCHAR(255) NOT NULL DEFAULT '',
                            \"body\" TEXT NULL,
                            \"created_at\" INTEGER NULL,
                            \"expires_at\" INTEGER NULL
                        )
                    ");
                    $this->db->executeStatement("CREATE INDEX \"ca_course_id_idx\" ON \"{$table}\" (\"course_id\")");
                    return;
                }

                $this->db->executeStatement("
                    CREATE TABLE {$table} (
                        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                        `course_id` BIGINT UNSIGNED NOT NULL,
                        `instructor_id` VARCHAR(64) NULL,
                        `title` VARCHAR(255) NOT NULL DEFAULT '',
                        `body` LONGTEXT NULL,
                        `created_at` BIGINT UNSIGNED NULL,
                        `expires_at` BIGINT UNSIGNED NULL,
                        PRIMARY KEY (`id`),
                        INDEX `ca_course_id_idx` (`course_id`)
                    )
                ");
            },
            $output
        );
    }

    private function ensureTable(
        string $prefix,
        string $legacyName,
        string $targetName,
        callable $createTable,
        IOutput $output
    ): void {
        $legacyFull = $prefix . $legacyName;
        $targetFull = $prefix . $targetName;

        if ($this->tableExists($targetFull)) {
            return;
        }

        if ($this->tableExists($legacyFull)) {
            // AUDIT v5.2.1 (pre-live review): quote both identifiers. On PostgreSQL unquoted
            // identifiers are case-folded to lowercase, so a mixed-case table prefix would make
            // RENAME fail to find the table (the CREATE branches already quote consistently).
            $q = $this->platformName() === 'mysql' ? '`' : '"';
            $this->db->executeStatement("ALTER TABLE {$q}{$legacyFull}{$q} RENAME TO {$q}{$targetFull}{$q}");
            $output->info("Renamed {$legacyName} -> {$targetName}");
            return;
            // Note: we deliberately do NOT fall through to create-from-scratch on a rename failure.
            // If the legacy table exists but the rename throws, creating an empty target table would
            // orphan the legacy data (the app would read the empty table) — a silent data-loss trap
            // (Codex #6). Letting the exception abort the migration lets an admin intervene.
        }

        $createTable($targetFull, $this->platformName());
        $output->info("Created {$targetName} from scratch (legacy and target table missing)");
    }

    private function tableExists(string $fullyQualifiedName): bool {
        try {
            $platform = $this->platformName();
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

    private function platformName(): string {
        // AUDIT v5.2.1 (pre-live review): no 'mysql' fallback. If platform detection ever fails,
        // defaulting to mysql would run MySQL-specific DDL (BIGINT UNSIGNED, backticks) against a
        // PostgreSQL/SQLite instance — a confusing downstream crash. Failing fast here aborts the
        // upgrade with the real cause instead. (Verified getName() works on the NC33 PG target.)
        return $this->db->getDatabasePlatform()->getName();
    }
}

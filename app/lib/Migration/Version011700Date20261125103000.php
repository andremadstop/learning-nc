<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Translations in Simplified Chinese: the CHECK constraint on the lang column of the
 * translation tables allows the languages of TranslationService::ALLOWED_LANGS (copied into LANGS).
 *
 * Version003200Date20260319193000 put a CHECK on learning_q_translations / learning_a_translations
 * that allows de/en/ru/ar only, in postSchemaChange(): it exists only on PostgreSQL instances
 * upgraded through 3.2 (a fresh install never runs postSchemaChange(), and its PostgreSQL-only
 * syntax never applied on MySQL/MariaDB). Version007900 renamed those tables to
 * learning_qst_translations / learning_ans_translations with ALTER TABLE RENAME, which keeps the
 * constraint. Version011600Date20261121140000 widened it to the six languages of 5.18.0 - that
 * migration has already run on an upgraded instance, so Chinese needs this one: without it a zh
 * translation passes the app's checks and then fails the insert; inside an import transaction a
 * failed statement poisons the whole PostgreSQL transaction, so the pool is lost, not just the
 * translation.
 *
 * The constraint is replaced under its own name, not dropped, so an upgraded instance keeps its
 * database-side guard; where there is none (fresh installs, MySQL/MariaDB) none is added - the
 * app validates the language in TranslationService::validateLang(). The legacy table names are
 * covered too, for an instance where Version007900 found both. Idempotent.
 *
 * The language list is a copy, not TranslationService::ALLOWED_LANGS: a migration must read no
 * app class that can change between releases (see Version011600Date20261121140000 and
 * MigrationAppClassesTest, which keeps LANGS equal to the service's list).
 */
class Version011700Date20261125103000 extends SimpleMigrationStep {
    /** TranslationService::ALLOWED_LANGS as of this change - a copy, see the class comment. */
    public const LANGS = ['de', 'en', 'fr', 'ru', 'ar', 'uk', 'zh'];

    public function __construct(
        private IDBConnection $db,
        private IConfig $config,
    ) {
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_POSTGRES) {
            return;
        }
        $prefix = $this->config->getSystemValueString('dbtableprefix', 'oc_');
        $allowed = 'ARRAY[' . implode(', ', array_map(
            static fn (string $lang): string => "'" . $lang . "'::character varying",
            self::LANGS
        )) . ']';

        foreach (['learning_qst_translations', 'learning_ans_translations', 'learning_q_translations', 'learning_a_translations'] as $table) {
            $existing = $this->langChecks($prefix . $table);
            // Only where one is left: a fresh install has no constraint and gets none.
            if ($existing === []) {
                continue;
            }
            foreach ($existing as $name) {
                $this->db->executeStatement('ALTER TABLE ' . $prefix . $table . ' DROP CONSTRAINT IF EXISTS ' . self::quote($name));
            }
            $this->db->executeStatement(
                'ALTER TABLE ' . $prefix . $table . ' ADD CONSTRAINT ' . self::quote($existing[0]) . " CHECK ((lang)::text = ANY (({$allowed})::text[]))"
            );
            $output->info('Translation languages widened to ' . implode(', ', self::LANGS) . ' on ' . $table);
        }
    }

    private static function quote(string $identifier): string {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    /**
     * Names of the CHECK constraints on the table's lang column; [] when the table is missing.
     *
     * @return list<string>
     */
    private function langChecks(string $table): array {
        $result = $this->db->executeQuery(
            "SELECT c.conname FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid
             WHERE t.relname = ? AND c.contype = 'c' AND pg_get_constraintdef(c.oid) LIKE '%lang%'",
            [$table]
        );
        $names = [];
        while (($name = $result->fetchOne()) !== false) {
            $names[] = (string)$name;
        }
        $result->closeCursor();
        return $names;
    }
}

<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCA\Learning\Service\TranslationService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Translations in French and Ukrainian (5.18.0): the CHECK constraint on the lang column of the
 * translation tables allows the languages of TranslationService::ALLOWED_LANGS.
 *
 * Version003200Date20260319193000 put a CHECK on learning_q_translations / learning_a_translations
 * that allows de/en/ru/ar only, in postSchemaChange(): it exists only on PostgreSQL instances
 * upgraded through 3.2 (a fresh install never runs postSchemaChange(), and its PostgreSQL-only
 * syntax never applied on MySQL/MariaDB). Version007900 then renamed those tables to
 * learning_qst_translations / learning_ans_translations — the tables the mappers use — with
 * ALTER TABLE RENAME, which keeps the constraint (still named <prefix>learning_q_translations_lang_check).
 * On those instances a French or Ukrainian translation would pass the app's checks and then fail
 * the insert; inside an import transaction a failed statement poisons the whole PostgreSQL
 * transaction, so the pool is lost, not just the translation.
 *
 * The constraint is replaced under its own name, not dropped, so an upgraded instance keeps its
 * database-side guard; where there is none (fresh installs, MySQL/MariaDB) none is added — the
 * app validates the language in TranslationService::validateLang(). The legacy table names are
 * covered too, for an instance where Version007900 found both. Idempotent.
 */
class Version011600Date20261121140000 extends SimpleMigrationStep {
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
            TranslationService::ALLOWED_LANGS
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
            $output->info('Translation languages widened to ' . implode(', ', TranslationService::ALLOWED_LANGS) . ' on ' . $table);
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

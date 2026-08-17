<?php
declare(strict_types=1);

namespace OCA\Learning\Command;

use OCP\IConfig;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * OCC command: remove every trace this app left in the database (issue #1).
 *
 * Usage:
 *   php occ learning:uninstall                              # dry run — prints the plan, changes nothing
 *   php occ learning:uninstall --execute --keep-certificates
 *   php occ learning:uninstall --execute --drop-certificates
 *   php occ occ app:remove learning                         # afterwards
 *
 * WHY THIS IS A COMMAND AND NOT A REPAIR STEP
 *
 * Nextcloud never drops migration-created tables on its own; Installer::removeApp() says so in
 * its own docblock. The only hook an app gets is <repair-steps><uninstall> in info.xml — and
 * AppManager::disableApp() executes that hook, meaning it also fires when an admin merely
 * *disables* the app in the Apps UI. A repair step cannot tell the two apart, so putting DROP
 * TABLE there would let one stray click destroy every course, pool and certificate on the
 * instance. Hence: explicit, opt-in, dry-run by default.
 *
 * ORDER OF OPERATIONS
 *
 * Metadata rows are deleted BEFORE the tables are dropped, and that order is load-bearing.
 * The oc_migrations rows are the dangerous half: drop the tables but leave those rows and a
 * later reinstall believes all migrations already ran, creates nothing, and boots broken. If
 * the run dies between the two phases, deleted-migrations + surviving-tables is the recoverable
 * state (every migration guards with hasTable()), while the reverse is not.
 */
class UninstallCommand extends Command {
    /**
     * Every table this app has ever created, without the instance's dbtableprefix.
     *
     * Includes the legacy names from both rename waves (Version002100, and Version007900 /
     * Version009900). Those migrations are self-healing, so on a healthy install the legacy
     * names are gone — but an upgrade that died midway can leave one behind, and a leftover
     * table is exactly what a "clean uninstall" is supposed to remove.
     *
     * UninstallCommandTest derives this set from the migration sources: adding a migration
     * that creates a table without extending this list fails the suite.
     *
     * @var list<string>
     */
    public const APP_TABLES = [
        'learning_ai_chat_memory',
        'learning_analytics',
        'learning_ans_translations',
        'learning_answers',
        'learning_assignments',
        'learning_audit_chain_state',
        'learning_audit_checkpoints',
        'learning_audit_events',
        'learning_campaign_state',
        'learning_cert_keys',
        'learning_certificates',
        'learning_coop_players',
        'learning_coop_sessions',
        'learning_coop_votes',
        'learning_course_announcements',
        'learning_course_curriculum_scopes',
        'learning_course_documents',
        'learning_course_exam_slots',
        'learning_course_members',
        'learning_course_pools',
        'learning_course_question_overrides',
        'learning_course_schedule',
        'learning_course_snapshots',
        'learning_course_videos',
        'learning_courses',
        'learning_duel_answers',
        'learning_duel_invites',
        'learning_duel_sessions',
        'learning_epoch_progress',
        'learning_feed_items',
        'learning_gameshow_answers',
        'learning_gameshow_players',
        'learning_gameshow_sessions',
        'learning_kudos',
        'learning_league_challenges',
        'learning_league_results',
        'learning_league_seasons',
        'learning_leitner_items',
        'learning_mission_claims',
        'learning_oversight',
        'learning_pool_shares',
        'learning_pools',
        'learning_qst_translations',
        'learning_questions',
        'learning_rag_chunks',
        'learning_recert_reminders',
        'learning_sessions',
        'learning_story_progress',
        'learning_support_tickets',
        'learning_user_answers',
        'learning_user_badges',
        'learning_user_stats',
        'learning_user_telos',
        'learning_video_progress',

        // Legacy names — wave 1 (renamed by Version002100)
        'learning_question_translations',
        'learning_answer_translations',
        'learning_user_mission_claims',

        // Legacy names — wave 2 (renamed by Version007900 / Version009900)
        'learning_q_translations',
        'learning_a_translations',
        'learning_q_overrides',
        'learning_curriculum_scopes',
        'learning_announcements',
    ];

    /**
     * Tables holding the issuer identity and the certificates signed with it.
     *
     * Dropping learning_cert_keys is the one irreversible act here: it holds the Ed25519
     * private key (ICrypto-encrypted), and without it every certificate ever issued becomes
     * permanently unverifiable — including through the public verify route. Retired keys are
     * kept on purpose so old certificates keep validating, so "just rotate" is no escape.
     *
     * @var list<string>
     */
    public const CERT_TABLES = [
        'learning_cert_keys',
        'learning_certificates',
    ];

    /**
     * Background job classes this app registers in oc_jobs.
     *
     * An explicit list rather than a LIKE pattern: the backslashes in a PHP namespace collide
     * with LIKE's escape character differently on MySQL and PostgreSQL, and a mis-escaped
     * pattern here would either miss rows or match a foreign app's jobs.
     *
     * UninstallCommandTest derives this set from lib/BackgroundJob/.
     *
     * @var list<string>
     */
    public const APP_JOBS = [
        'OCA\Learning\BackgroundJob\AuditCheckpointJob',
        'OCA\Learning\BackgroundJob\ChunkingJob',
        'OCA\Learning\BackgroundJob\ConsistencyCheckJob',
        'OCA\Learning\BackgroundJob\ImportUsersJob',
        'OCA\Learning\BackgroundJob\NotificationJob',
        'OCA\Learning\BackgroundJob\RecertPeriodCloseJob',
        'OCA\Learning\BackgroundJob\RetentionJob',
        'OCA\Learning\BackgroundJob\SendRemindersJob',
        'OCA\Learning\BackgroundJob\WeeklyLernplanJob',
    ];

    private const APP_ID = 'learning';

    public function __construct(
        private IDBConnection $db,
        private IConfig $config,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('learning:uninstall')
            ->setDescription('Remove all Learning data from the database (dry run unless --execute)')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Actually delete. Without this the command only prints what it would do.')
            ->addOption('keep-certificates', null, InputOption::VALUE_NONE, 'Preserve issued certificates and the issuer signing key')
            ->addOption('drop-certificates', null, InputOption::VALUE_NONE, 'Delete certificates and the issuer key too — irreversible, past certificates stop verifying');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $execute = (bool)$input->getOption('execute');
        $keepCerts = (bool)$input->getOption('keep-certificates');
        $dropCerts = (bool)$input->getOption('drop-certificates');

        if ($keepCerts && $dropCerts) {
            $output->writeln('<error>--keep-certificates and --drop-certificates are mutually exclusive.</error>');
            return self::INVALID;
        }

        $prefix = (string)$this->config->getSystemValue('dbtableprefix', 'oc_');
        if (preg_match('/^[A-Za-z0-9_]*$/', $prefix) !== 1) {
            $output->writeln('<error>Refusing to run: dbtableprefix contains unexpected characters.</error>');
            return self::FAILURE;
        }

        $found = $this->discoverTables($prefix);
        $known = array_map(static fn (string $t) => $prefix . $t, self::APP_TABLES);
        $certTables = array_map(static fn (string $t) => $prefix . $t, self::CERT_TABLES);

        $tables = array_values(array_intersect($found, $known));
        $strays = array_values(array_diff($found, $known));
        $certPresent = array_values(array_intersect($tables, $certTables));

        if ($keepCerts) {
            $tables = array_values(array_diff($tables, $certTables));
        }

        /** @var array<string, int> $rowCounts */
        $rowCounts = [];

        // ── report ───────────────────────────────────────────────────────────
        $output->writeln('');
        $output->writeln($execute ? '<comment>Removing Learning data</comment>' : '<comment>Dry run — nothing will be changed</comment>');
        $output->writeln('');

        $totalRows = 0;
        if ($tables === []) {
            $output->writeln('  No Learning tables found.');
        } else {
            $output->writeln('  Tables to drop:');
            foreach ($tables as $table) {
                $rows = $this->countRows($table);
                $rowCounts[$table] = $rows;
                $totalRows += $rows;
                $output->writeln(sprintf('    %-42s %8s rows', $table, number_format($rows)));
            }
        }

        if ($strays !== []) {
            $output->writeln('');
            $output->writeln('  <comment>Tables matching the learning_ prefix but unknown to this version — NOT touched:</comment>');
            foreach ($strays as $table) {
                $output->writeln('    ' . $table);
            }
        }

        if ($keepCerts && $certPresent !== []) {
            $output->writeln('');
            $output->writeln('  <info>Kept (--keep-certificates):</info> ' . implode(', ', $certPresent));
        }

        $output->writeln('');
        $output->writeln('  Metadata rows to delete:');
        $scopes = $this->metadataScopes($prefix);
        $metaTotal = 0;
        foreach ($scopes as $scope) {
            $count = $this->countScope($scope);
            $metaTotal += max(0, $count);
            $output->writeln(sprintf(
                '    %-42s %8s',
                $scope['table'] . ' (' . $scope['label'] . ')',
                $count < 0 ? 'absent' : number_format($count) . ' rows'
            ));
        }

        $output->writeln('');
        $output->writeln(sprintf('  Total: %s tables, %s rows in tables, %s metadata rows.', count($tables), number_format($totalRows), number_format($metaTotal)));

        if (!$execute) {
            $output->writeln('');
            $output->writeln('  Re-run with <info>--execute</info> to apply. Afterwards run <info>occ app:remove learning</info>.');
            $output->writeln('  Course documents, videos and images live in the users\' own files and are never touched.');
            $output->writeln('');
            return self::SUCCESS;
        }

        // ── the one irreversible decision ────────────────────────────────────
        // Empty cert tables are not worth an admin's decision — only actual issuer material is.
        $certWithData = array_values(array_filter($certPresent, static fn (string $t) => ($rowCounts[$t] ?? 0) > 0));

        if ($certWithData !== [] && !$keepCerts && !$dropCerts) {
            $output->writeln('');
            $output->writeln('<error>Refusing to run: this instance has issuer/certificate data.</error>');
            $output->writeln('Dropping ' . implode(' and ', $certWithData) . ' destroys the Ed25519 issuer key.');
            $output->writeln('Every certificate ever issued becomes permanently unverifiable, including via the public verify route.');
            $output->writeln('');
            $output->writeln('Choose explicitly: <info>--keep-certificates</info> or <info>--drop-certificates</info>.');
            $output->writeln('');
            return self::INVALID;
        }

        return $this->applyPlan($output, $prefix, $tables, $scopes);
    }

    /**
     * Delete metadata first, then drop tables. See the class docblock for why that order.
     *
     * @param list<string>                                                          $tables
     * @param list<array{label: string, table: string, column: string, values: list<string>}> $scopes
     */
    private function applyPlan(OutputInterface $output, string $prefix, array $tables, array $scopes): int {
        $output->writeln('');
        $output->writeln('  Deleting metadata rows...');
        $this->db->beginTransaction();
        try {
            foreach ($scopes as $scope) {
                if (!$this->tableExists($scope['table'])) {
                    continue;
                }
                $placeholders = implode(', ', array_fill(0, count($scope['values']), '?'));
                $this->db->executeStatement(
                    sprintf('DELETE FROM "%s" WHERE "%s" IN (%s)', $scope['table'], $scope['column'], $placeholders),
                    $scope['values']
                );
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $output->writeln('<error>Metadata cleanup failed, nothing was dropped: ' . $e->getMessage() . '</error>');
            return self::FAILURE;
        }

        $output->writeln('  Dropping tables...');
        $failed = [];
        foreach ($tables as $table) {
            try {
                // CASCADE on PostgreSQL also removes dependent constraints; MySQL/SQLite ignore
                // the keyword, so it is appended only where it parses.
                $cascade = $this->platform() === 'pgsql' ? ' CASCADE' : '';
                $this->db->executeStatement(sprintf('DROP TABLE IF EXISTS "%s"%s', $table, $cascade));
            } catch (\Throwable $e) {
                $failed[] = $table . ' (' . $e->getMessage() . ')';
            }
        }

        $output->writeln('');
        if ($failed !== []) {
            $output->writeln('<error>Some tables could not be dropped:</error>');
            foreach ($failed as $line) {
                $output->writeln('  ' . $line);
            }
            return self::FAILURE;
        }

        $output->writeln('<info>Learning data removed.</info>');
        $output->writeln('Now run <info>occ app:remove learning</info> to remove the app itself.');
        $output->writeln('');
        return self::SUCCESS;
    }

    /**
     * Every table carrying this app's prefix, straight from the database catalogue.
     *
     * Reading the catalogue rather than probing 62 names one by one costs a single query and
     * surfaces tables a future version created but this version does not know about — those are
     * reported and deliberately left alone.
     *
     * @return list<string>
     */
    private function discoverTables(string $prefix): array {
        $pattern = $prefix . 'learning\_%';
        $sql = match ($this->platform()) {
            'sqlite3' => "SELECT name AS table_name FROM sqlite_master WHERE type = 'table' AND name LIKE ? ESCAPE '\\'",
            'pgsql' => "SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_name LIKE ? ESCAPE '\\'",
            default => "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ? ESCAPE '\\\\'",
        };

        try {
            $result = $this->db->executeQuery($sql, [$pattern]);
            $rows = $result->fetchAll();
            $result->closeCursor();
        } catch (\Throwable $e) {
            return [];
        }

        $names = [];
        foreach ($rows as $row) {
            $name = $row['table_name'] ?? $row['TABLE_NAME'] ?? $row['name'] ?? null;
            if (is_string($name)) {
                $names[] = $name;
            }
        }
        sort($names);
        return $names;
    }

    private function countRows(string $table): int {
        try {
            $result = $this->db->executeQuery(sprintf('SELECT COUNT(*) FROM "%s"', $table));
            $count = (int)$result->fetchOne();
            $result->closeCursor();
            return $count;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * The non-table rows this app leaves behind, in deletion order.
     *
     * oc_migrations comes first because it is the one that breaks reinstalls when forgotten.
     * notifications and activity belong to optional apps and may not exist at all.
     *
     * @return list<array{label: string, table: string, column: string, values: list<string>}>
     */
    private function metadataScopes(string $prefix): array {
        return [
            ['label' => 'migration bookkeeping', 'table' => $prefix . 'migrations', 'column' => 'app', 'values' => [self::APP_ID]],
            ['label' => 'app settings', 'table' => $prefix . 'appconfig', 'column' => 'appid', 'values' => [self::APP_ID]],
            ['label' => 'per-user settings', 'table' => $prefix . 'preferences', 'column' => 'appid', 'values' => [self::APP_ID]],
            ['label' => 'background jobs', 'table' => $prefix . 'jobs', 'column' => 'class', 'values' => self::APP_JOBS],
            ['label' => 'notifications', 'table' => $prefix . 'notifications', 'column' => 'app', 'values' => [self::APP_ID]],
            ['label' => 'activity stream', 'table' => $prefix . 'activity', 'column' => 'app', 'values' => [self::APP_ID]],
        ];
    }

    /**
     * @param array{label: string, table: string, column: string, values: list<string>} $scope
     * @return int row count, or -1 when the table does not exist on this instance
     */
    private function countScope(array $scope): int {
        $placeholders = implode(', ', array_fill(0, count($scope['values']), '?'));
        try {
            $result = $this->db->executeQuery(
                sprintf('SELECT COUNT(*) FROM "%s" WHERE "%s" IN (%s)', $scope['table'], $scope['column'], $placeholders),
                $scope['values']
            );
            $count = (int)$result->fetchOne();
            $result->closeCursor();
            return $count;
        } catch (\Throwable $e) {
            return -1;
        }
    }

    private function tableExists(string $table): bool {
        try {
            $result = $this->db->executeQuery(sprintf('SELECT 1 FROM "%s" WHERE 1 = 0', $table));
            $result->closeCursor();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function platform(): string {
        return (string)$this->config->getSystemValue('dbtype', 'sqlite3');
    }
}

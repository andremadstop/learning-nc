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
 * The refinement worth recording, because it will be proposed again: a repair step *armed* by a
 * marker this command sets, so a plain disable stays a no-op. It has a real advantage — running
 * inside disableApp() is the only genuinely quiesced moment available, since Nextcloud loads an
 * app's commands only while the app is enabled, and in maintenance mode loads none but
 * app_api's. It is still rejected. It puts a live delete path on the disable hook, and every way
 * the marker check can go wrong (a stale value, a cached read, a database restore, an inverted
 * comparison) ends in total data loss from a mis-click. What it buys is a handful of orphaned
 * notification/job/activity rows — recoverable, and largely handled by clearReappearedRows().
 * Small recoverable problem, rare irreversible one: not a trade worth making.
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

    /**
     * Settings this app writes into OTHER apps' namespaces.
     *
     * Application.php points the instance-wide legal links at this app's own routes. Left behind
     * after removal, Impressum and Privacy 404 for every user on the server — a foreign app's
     * setting, broken by ours, so cleaning it up is our job.
     *
     * Reported, never deleted by this command: Application::boot() rewrites them on every
     * request while the app is enabled, and the command necessarily runs while it is. A DELETE
     * here would be undone by the very next page load — including by `occ app:remove`, which
     * boots the app before disabling it. So the command prints the statement to run afterwards.
     *
     * The value guard in that statement matters: an admin may have set their own URL since, and
     * that one is not ours to delete.
     */
    private const FOREIGN_APPCONFIG = [
        ['app' => 'theming', 'keys' => ['privacyUrl', 'imprintUrl'], 'valueLike' => '%/apps/learning/%'],
    ];

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
        $unreadable = [];
        if ($tables === []) {
            $output->writeln('  No Learning tables found.');
        } else {
            $output->writeln('  Tables to drop:');
            foreach ($tables as $table) {
                $probe = $this->probeCount(sprintf('SELECT COUNT(*) FROM %s', $this->quote($table)));
                if ($probe['state'] !== 'ok') {
                    $unreadable[] = $table . ' (' . $probe['error'] . ')';
                    $output->writeln(sprintf('    %-42s %8s', $table, '<error>unreadable</error>'));
                    continue;
                }
                $rowCounts[$table] = $probe['count'];
                $totalRows += $probe['count'];
                $output->writeln(sprintf('    %-42s %8s rows', $table, number_format($probe['count'])));
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
        $presentScopes = [];
        foreach ($scopes as $scope) {
            $probe = $this->probeScope($scope);
            if ($probe['state'] === 'ok') {
                $metaTotal += $probe['count'];
                $presentScopes[] = $scope;
            } elseif ($probe['state'] === 'error') {
                $unreadable[] = $scope['table'] . ' (' . $probe['error'] . ')';
            }
            $output->writeln(sprintf(
                '    %-42s %8s',
                $scope['table'] . ' (' . $scope['label'] . ')',
                match ($probe['state']) {
                    'absent' => 'absent',
                    'error' => '<error>unreadable</error>',
                    default => number_format($probe['count']) . ' rows',
                }
            ));
        }

        // ── settings we planted in other apps ────────────────────────────────
        $foreign = [];
        foreach (self::FOREIGN_APPCONFIG as $entry) {
            $probe = $this->probeForeignAppConfig($prefix, $entry);
            if ($probe['state'] === 'error') {
                $unreadable[] = $prefix . 'appconfig/' . $entry['app'] . ' (' . $probe['error'] . ')';
                continue;
            }
            if ($probe['state'] === 'ok' && $probe['count'] > 0) {
                $foreign[] = ['entry' => $entry, 'count' => $probe['count']];
            }
        }

        $output->writeln('');
        $output->writeln('  <comment>Left for you — this command cannot do these:</comment>');
        foreach ($foreign as $item) {
            $keys = implode("', '", $item['entry']['keys']);
            $output->writeln(sprintf(
                '    %sappconfig holds %s rows for "%s" (%s) that this app pointed at its own routes.',
                $prefix,
                number_format($item['count']),
                $item['entry']['app'],
                str_replace("', '", ', ', $keys)
            ));
            $output->writeln('    Deleting them here would be pointless: Application::boot() rewrites them on the next');
            $output->writeln('    request, and app:remove boots the app before disabling it. Run this AFTER app:remove:');
            $output->writeln(sprintf(
                "      DELETE FROM %s WHERE appid = '%s' AND configkey IN ('%s') AND configvalue LIKE '%s';",
                $prefix . 'appconfig',
                $item['entry']['app'],
                $keys,
                $item['entry']['valueLike']
            ));
            $output->writeln('    Otherwise the instance-wide Imprint and Privacy links stay pointing at a removed app.');
        }
        $output->writeln('    ' . $prefix . 'preferences (dashboard/layout) may still list the "learning" widget.');
        $output->writeln('    That row holds your other widgets too, so remove the token by hand rather than the row.');
        $output->writeln('    Files the app created in users\' homes under /Learning/ stay as ordinary user files.');

        $output->writeln('');
        $output->writeln(sprintf('  Total: %s tables, %s rows in tables, %s metadata rows.', count($tables), number_format($totalRows), number_format($metaTotal)));

        if (!$execute) {
            $output->writeln('');
            $output->writeln('  Re-run with <info>--execute</info> to apply. Afterwards run <info>occ app:remove learning</info>.');
            $output->writeln('  No files are touched: uploaded course material stays, and so do the files this app');
            $output->writeln('  created under /Learning/ in each user\'s home — they belong to the user now.');
            $output->writeln('');
            return self::SUCCESS;
        }

        // ── refuse to act on a plan we could not fully read ──────────────────
        // Every count above either succeeded or proved the table absent. Anything else — a
        // permission error, a lost connection, a column that is not what we expect — leaves us
        // guessing, and every guess here is destructive: an unreadable oc_migrations would be
        // skipped while the tables are dropped anyway, and an unreadable cert_keys would read as
        // "no certificates" and disarm the gate below.
        if ($unreadable !== []) {
            $output->writeln('');
            $output->writeln('<error>Refusing to run: some tables could not be read.</error>');
            foreach ($unreadable as $line) {
                $output->writeln('  ' . $line);
            }
            $output->writeln('');
            $output->writeln('Fix the access problem and re-run. Nothing was changed.');
            $output->writeln('');
            return self::FAILURE;
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

        return $this->applyPlan($output, $tables, $presentScopes);
    }

    /**
     * Delete metadata first, then drop tables. See the class docblock for why that order.
     *
     * $scopes must already be filtered to tables that exist — the caller does that from the
     * report phase, where countScope() returns -1 for an absent table. Probing in here instead
     * would mean issuing a query that fails by design, and on PostgreSQL a failed statement
     * aborts the whole transaction block (SQLSTATE 25P02), after which COMMIT succeeds while
     * acting as a rollback. A swallowed probe error would therefore discard every delete without
     * raising anything, and the run would still drop the tables: precisely the unreinstallable
     * state the delete-before-drop order exists to prevent, reported as success. Verified against
     * a real PostgreSQL 16 — see scripts/db-uninstall-probe.php.
     *
     * @param list<string>                                                                    $tables
     * @param list<array{label: string, table: string, column: string, values: list<string>}> $scopes
     */
    private function applyPlan(OutputInterface $output, array $tables, array $scopes): int {
        $output->writeln('');
        $output->writeln('  Deleting metadata rows...');
        $this->db->beginTransaction();
        try {
            foreach ($scopes as $scope) {
                $placeholders = implode(', ', array_fill(0, count($scope['values']), '?'));
                $this->db->executeStatement(
                    sprintf('DELETE FROM %s WHERE %s IN (%s)', $this->quote($scope['table']), $this->quote($scope['column']), $placeholders),
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
        if ($tables !== []) {
            $error = $this->dropTables($tables);
            if ($error !== null) {
                $output->writeln('');
                $output->writeln('<error>Tables could not be dropped: ' . $error . '</error>');
                $output->writeln('The metadata rows are already gone; re-run after resolving this, or drop the tables by hand.');
                return self::FAILURE;
            }
        }

        $this->clearReappearedRows($output, $scopes);

        $output->writeln('<info>Learning data removed.</info>');
        $output->writeln('Now run <info>occ app:remove learning</info> to remove the app itself.');
        $output->writeln('');
        return self::SUCCESS;
    }

    /**
     * Clear metadata rows written while this command was running.
     *
     * The app is necessarily still enabled during the run: Nextcloud loads an app's commands
     * only for enabled apps, and in maintenance mode it loads none but app_api's
     * (Console\Application), so there is no quiesced state from which to run this. A request or
     * cron job already in flight can therefore write a notification, job or activity row after
     * the deletes committed.
     *
     * A second pass after the tables are gone catches those. It does not close the window — a
     * write landing after this recheck is still possible — so when it finds something, it says
     * so instead of reporting a clean sweep.
     *
     * The alternative would be an uninstall repair step armed by a marker, which runs inside
     * disableApp() and would genuinely quiesce. It is deliberately not used: it puts a live
     * delete path on the disable hook, where a stale marker, a cached read or an inverted
     * comparison turns a mis-click into total data loss. Trading a small recoverable problem
     * (a few orphaned rows) for a rare irreversible one is a bad exchange.
     *
     * @param list<array{label: string, table: string, column: string, values: list<string>}> $scopes
     */
    private function clearReappearedRows(OutputInterface $output, array $scopes): void {
        $cleared = [];
        foreach ($scopes as $scope) {
            $probe = $this->probeScope($scope);
            if ($probe['state'] !== 'ok' || $probe['count'] === 0) {
                continue;
            }
            $placeholders = implode(', ', array_fill(0, count($scope['values']), '?'));
            try {
                $this->db->executeStatement(
                    sprintf(
                        'DELETE FROM %s WHERE %s IN (%s)',
                        $this->quote($scope['table']),
                        $this->quote($scope['column']),
                        $placeholders
                    ),
                    $scope['values']
                );
                $cleared[] = $scope['table'] . ' (' . number_format($probe['count']) . ')';
            } catch (\Throwable $e) {
                $cleared[] = $scope['table'] . ' (FAILED: ' . $e->getMessage() . ')';
            }
        }

        if ($cleared === []) {
            return;
        }

        $output->writeln('');
        $output->writeln('  <comment>Rows reappeared while this ran and were cleared: ' . implode(', ', $cleared) . '</comment>');
        $output->writeln('  The app stays enabled until you run app:remove, so a request in flight can still write');
        $output->writeln('  one more row after this check. Re-run the dry run afterwards if you want certainty.');
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
        // '!' rather than a backslash as LIKE escape: a backslash inside a SQL string literal
        // means one thing on PostgreSQL and another on MySQL, and this is the one branch that
        // cannot be exercised on the PostgreSQL dev instance.
        $literal = $prefix . 'learning_';
        $pattern = preg_replace('/([!%_])/', '!$1', $literal) . '%';
        $sql = match ($this->platform()) {
            'sqlite3' => "SELECT name AS table_name FROM sqlite_master WHERE type = 'table' AND name LIKE ? ESCAPE '!'",
            'pgsql' => "SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_name LIKE ? ESCAPE '!'",
            default => "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ? ESCAPE '!'",
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

    /**
     * Drop every table in ONE statement, because they reference each other.
     *
     * The schema has real foreign keys (questions→pools, sessions→pools, user_answers→sessions
     * and →questions). Dropping one table at a time in alphabetical order therefore fails on
     * every parent that still has children — verified against MariaDB, where pools, questions
     * and sessions all refuse. Naming them together resolves the keys between them.
     *
     * PostgreSQL: RESTRICT, not CASCADE. CASCADE would also delete a view or a foreign key
     * belonging to somebody else that happens to depend on a Learning table; RESTRICT makes the
     * statement fail loudly instead, which is the answer an admin can act on.
     *
     * MySQL/MariaDB has no such choice — it refuses to drop a referenced parent at all — so the
     * checks are disabled around the statement and restored in a finally.
     *
     * @param list<string> $tables
     * @return string|null error message, or null on success
     */
    private function dropTables(array $tables): ?string {
        $quoted = implode(', ', array_map(fn (string $t) => $this->quote($t), $tables));
        $mysql = $this->platform() === 'mysql';

        try {
            if ($mysql) {
                $this->db->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            }
            $this->db->executeStatement(
                'DROP TABLE ' . $quoted . ($mysql ? '' : ' RESTRICT')
            );
        } catch (\Throwable $e) {
            return $e->getMessage();
        } finally {
            if ($mysql) {
                try {
                    $this->db->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
                } catch (\Throwable $e) {
                    // Session-scoped; a failure here cannot leave the database in a worse state
                    // than the connection already is.
                }
            }
        }

        return null;
    }

    /**
     * @param array{app: string, keys: list<string>, valueLike: string} $entry
     * @return array{state: 'ok'|'absent'|'error', count: int, error: ?string}
     */
    private function probeForeignAppConfig(string $prefix, array $entry): array {
        return $this->probeCount(
            $this->foreignAppConfigSql($prefix, $entry, 'SELECT COUNT(*) FROM %s'),
            $this->foreignAppConfigParams($entry)
        );
    }

    /**
     * @param array{app: string, keys: list<string>, valueLike: string} $entry
     */
    private function foreignAppConfigSql(string $prefix, array $entry, string $verb): string {
        $keyPlaceholders = implode(', ', array_fill(0, count($entry['keys']), '?'));
        return sprintf($verb, $this->quote($prefix . 'appconfig'))
            . sprintf(
                ' WHERE %s = ? AND %s IN (%s) AND %s LIKE ?',
                $this->quote('appid'),
                $this->quote('configkey'),
                $keyPlaceholders,
                $this->quote('configvalue')
            );
    }

    /**
     * @param array{app: string, keys: list<string>, valueLike: string} $entry
     * @return list<string>
     */
    private function foreignAppConfigParams(array $entry): array {
        return array_merge([$entry['app']], $entry['keys'], [$entry['valueLike']]);
    }

    /**
     * Run a COUNT and say plainly whether it worked, the table is absent, or something else broke.
     *
     * The distinction is the whole point: every caller does something destructive with the answer,
     * and mapping an unexpected failure onto 0 or "absent" is what turns a broken run into one
     * that looks clean.
     *
     * @param list<string> $params
     * @return array{state: 'ok'|'absent'|'error', count: int, error: ?string}
     */
    private function probeCount(string $sql, array $params = []): array {
        try {
            $result = $this->db->executeQuery($sql, $params);
            $count = (int)$result->fetchOne();
            $result->closeCursor();
            return ['state' => 'ok', 'count' => $count, 'error' => null];
        } catch (\Throwable $e) {
            return $this->isMissingTable($e)
                ? ['state' => 'absent', 'count' => 0, 'error' => null]
                : ['state' => 'error', 'count' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Is this exception "no such table", as opposed to any other failure?
     *
     * OCP\DB\Exception carries a portable reason; PDO/Doctrine exceptions reaching us unwrapped
     * carry the SQLSTATE as their code (42P01 on PostgreSQL, 42S02 on MySQL). Anything we cannot
     * positively identify counts as a real error — the safe direction, since "absent" makes the
     * command skip work.
     */
    private function isMissingTable(\Throwable $e): bool {
        if ($e instanceof \OCP\DB\Exception && $e->getReason() === \OCP\DB\Exception::REASON_DATABASE_OBJECT_NOT_FOUND) {
            return true;
        }
        return in_array((string)$e->getCode(), ['42P01', '42S02'], true);
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
            ['label' => 'activity mail queue', 'table' => $prefix . 'activity_mq', 'column' => 'amq_appid', 'values' => [self::APP_ID]],
        ];
    }

    /**
     * @param array{label: string, table: string, column: string, values: list<string>} $scope
     * @return array{state: 'ok'|'absent'|'error', count: int, error: ?string}
     */
    private function probeScope(array $scope): array {
        $placeholders = implode(', ', array_fill(0, count($scope['values']), '?'));
        return $this->probeCount(
            sprintf(
                'SELECT COUNT(*) FROM %s WHERE %s IN (%s)',
                $this->quote($scope['table']),
                $this->quote($scope['column']),
                $placeholders
            ),
            $scope['values']
        );
    }

    /**
     * Quote an identifier the way THIS database expects it.
     *
     * MySQL/MariaDB reads "..." as a string literal unless ANSI_QUOTES is set, which Nextcloud
     * does not set — so ANSI quoting there turns every statement into a syntax error. Because
     * the callers below swallow query errors to stay robust on partial installs, that failure
     * would surface as "0 rows" and "absent" rather than as an error: a broken run looking
     * exactly like a clean instance.
     *
     * Only constants and the prefix-validated dbtableprefix reach this method.
     */
    private function quote(string $identifier): string {
        return $this->platform() === 'mysql'
            ? '`' . $identifier . '`'
            : '"' . $identifier . '"';
    }

    private function platform(): string {
        return (string)$this->config->getSystemValue('dbtype', 'sqlite3');
    }
}

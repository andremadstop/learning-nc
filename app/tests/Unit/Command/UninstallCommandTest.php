<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Command;

use OCA\Learning\Command\UninstallCommand;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\StubConsoleInput;

/**
 * A connection where nominated tables do not exist.
 *
 * FakeDbConnection never throws, so it cannot reach the command's catch-blocks — and those
 * blocks are exactly where a query failure gets mapped to a benign-looking default ("0 rows",
 * "absent"). Every instance without the activity app takes that path.
 */
final class PartialDbConnection implements \OCP\IDBConnection {
    public array $executedStatements = [];
    public array $executedQueries = [];

    /** @var list<FakeResult> */
    private array $rawResults;

    /**
     * @param list<string> $missingTables tables that genuinely do not exist
     * @param list<string> $brokenTables  tables that exist but whose queries fail for another
     *                                    reason (permissions, connection, a column typo) — the
     *                                    case the command must NOT confuse with "absent"
     */
    public function __construct(
        private array $missingTables,
        array $rawResults = [],
        private array $brokenTables = [],
    ) {
        $this->rawResults = $rawResults;
    }

    private function guard(string $sql): void {
        foreach ($this->brokenTables as $table) {
            if (str_contains($sql, $table)) {
                throw new \OCP\DB\Exception(0, 'permission denied for table ' . $table);
            }
        }
        foreach ($this->missingTables as $table) {
            if (str_contains($sql, $table)) {
                throw new \OCP\DB\Exception(
                    \OCP\DB\Exception::REASON_DATABASE_OBJECT_NOT_FOUND,
                    'relation "' . $table . '" does not exist'
                );
            }
        }
    }

    public function getQueryBuilder(): FakeQueryBuilder {
        return new FakeQueryBuilder();
    }

    public function executeQuery(string $sql, array $params = []): FakeResult {
        $this->guard($sql);
        $this->executedQueries[] = ['sql' => $sql, 'params' => $params];
        return array_shift($this->rawResults) ?? new FakeResult();
    }

    public function executeStatement(string $sql, array $params = []): int {
        $this->guard($sql);
        $this->executedStatements[] = ['sql' => $sql, 'params' => $params];
        return 1;
    }

    public function escapeLikeParameter(string $input): string {
        return addcslashes($input, '\\%_');
    }

    public function beginTransaction(): void {}
    public function commit(): void {}
    public function rollBack(): void {}
    public function inTransaction(): bool {
        return false;
    }
}

/**
 * A connection with PostgreSQL's transaction semantics.
 *
 * On PostgreSQL a failed statement aborts the entire transaction block (SQLSTATE 25P02): every
 * later statement in it fails, and COMMIT on an aborted block *succeeds* while acting as a
 * rollback. So a swallowed query error inside a transaction silently discards everything the
 * transaction did — without raising anything the caller can see.
 *
 * MariaDB does not behave this way (a failed statement fails alone), which is why the MariaDB
 * probe cannot catch it, and PartialDbConnection's no-op transaction methods cannot either.
 */
final class PostgresLikeDbConnection implements \OCP\IDBConnection {
    /** Statements that actually took effect — i.e. survived a commit. */
    public array $effectiveStatements = [];
    public bool $silentlyRolledBack = false;

    private bool $inTx = false;
    private bool $poisoned = false;
    private array $pending = [];

    /** @var list<FakeResult> */
    private array $rawResults;

    /** @param list<string> $missingTables */
    public function __construct(private array $missingTables, array $rawResults = []) {
        $this->rawResults = $rawResults;
    }

    private function guard(string $sql): void {
        if ($this->inTx && $this->poisoned) {
            throw new \RuntimeException('current transaction is aborted, commands ignored until end of transaction block');
        }
        foreach ($this->missingTables as $table) {
            if (str_contains($sql, $table)) {
                if ($this->inTx) {
                    $this->poisoned = true;
                }
                throw new \OCP\DB\Exception(
                    \OCP\DB\Exception::REASON_DATABASE_OBJECT_NOT_FOUND,
                    'relation "' . $table . '" does not exist'
                );
            }
        }
    }

    public function getQueryBuilder(): FakeQueryBuilder {
        return new FakeQueryBuilder();
    }

    public function executeQuery(string $sql, array $params = []): FakeResult {
        $this->guard($sql);
        return array_shift($this->rawResults) ?? new FakeResult();
    }

    public function executeStatement(string $sql, array $params = []): int {
        $this->guard($sql);
        if ($this->inTx) {
            $this->pending[] = $sql;
        } else {
            $this->effectiveStatements[] = $sql;
        }
        return 1;
    }

    public function escapeLikeParameter(string $input): string {
        return addcslashes($input, '\\%_');
    }

    public function beginTransaction(): void {
        $this->inTx = true;
        $this->poisoned = false;
        $this->pending = [];
    }

    public function commit(): void {
        if ($this->poisoned) {
            // PostgreSQL answers COMMIT with ROLLBACK here — no error reaches the caller.
            $this->silentlyRolledBack = true;
            $this->pending = [];
        } else {
            $this->effectiveStatements = array_merge($this->effectiveStatements, $this->pending);
        }
        $this->pending = [];
        $this->inTx = false;
        $this->poisoned = false;
    }

    public function rollBack(): void {
        $this->pending = [];
        $this->inTx = false;
        $this->poisoned = false;
    }

    public function inTransaction(): bool {
        return $this->inTx;
    }
}

/**
 * Issue #1 — UninstallCommand.
 *
 * The load-bearing assertion is the first one: APP_TABLES must cover every table any
 * migration has ever created, including the legacy names from both rename waves. A table
 * missing from that list survives the uninstall and silently breaks a later reinstall.
 * The test derives the expected set from the migration sources, so adding a migration
 * without extending APP_TABLES fails here rather than on a stranger's server.
 */
class UninstallCommandTest extends TestCase {

    private const MIGRATION_DIR = __DIR__ . '/../../../lib/Migration';

    /**
     * Every table name any migration creates or probes for.
     *
     * Covers the three ways this app has produced tables over its history:
     *   createTable('x')                      — the Doctrine schema path
     *   hasTable('x')                         — legacy-name probes in rename migrations
     *   ensureTable($prefix, 'old', 'new')    — the raw-SQL self-healing path (Version009900)
     *
     * Index names share the learning_ prefix, so a blanket string scan is not usable here.
     */
    private function tableNamesFromMigrations(): array {
        $names = [];
        foreach (glob(self::MIGRATION_DIR . '/*.php') ?: [] as $file) {
            $src = file_get_contents($file);
            if ($src === false) {
                continue;
            }
            preg_match_all("/(?:createTable|hasTable)\(\s*'(learning_[a-z0-9_]+)'/", $src, $m);
            $names = array_merge($names, $m[1]);
            preg_match_all("/ensureTable\(\s*\\\$prefix,\s*'(learning_[a-z0-9_]+)',\s*'(learning_[a-z0-9_]+)'/", $src, $m2);
            $names = array_merge($names, $m2[1], $m2[2]);
        }
        $names = array_unique($names);
        sort($names);
        return $names;
    }

    public function testAppTablesCoversEveryTableTheMigrationsCreate(): void {
        $fromMigrations = $this->tableNamesFromMigrations();

        // Guard against the regex silently matching nothing and making the test vacuous.
        $this->assertGreaterThan(50, count($fromMigrations), 'migration scan found suspiciously few tables');

        $missing = array_values(array_diff($fromMigrations, UninstallCommand::APP_TABLES));

        $this->assertSame([], $missing, 'APP_TABLES is missing tables that migrations create: ' . implode(', ', $missing));
    }

    // ── command behaviour ────────────────────────────────────────────────────

    /**
     * @param list<string> $existingTables table names as the catalogue returns them (with prefix)
     */
    private function makeCommand(array $existingTables, int $certRows = 0, string $dbType = 'pgsql'): array {
        // One catalogue query returns every learning_* table, then one COUNT per table.
        // The command sorts the catalogue result, so the COUNT queue must follow that order.
        $results = [FakeResult::fromFetchAll(array_map(static fn ($t) => ['table_name' => $t], $existingTables))];
        sort($existingTables);
        foreach ($existingTables as $table) {
            $isCert = str_contains($table, 'cert');
            $results[] = FakeResult::fromFetchOne($isCert ? $certRows : 0);
        }
        // Row counts for the metadata scopes, then one for the foreign-appconfig probe.
        foreach (range(1, 7) as $ignored) {
            $results[] = FakeResult::fromFetchOne(0);
        }
        $results[] = FakeResult::fromFetchOne(2);
        $db = new FakeDbConnection([], $results);

        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValue')->willReturnCallback(
            static fn (string $key, $default = '') => match ($key) {
                'dbtableprefix' => 'oc_',
                'dbtype' => $dbType,
                default => $default,
            }
        );

        return [new UninstallCommand($db, $config), $db];
    }

    private function destructiveStatements(object $db): array {
        return array_values(array_filter(
            array_column($db->executedStatements, 'sql'),
            static fn (string $sql) => preg_match('/^\s*(DROP|DELETE|TRUNCATE)/i', $sql) === 1
        ));
    }

    public function testDryRunIsTheDefaultAndExecutesNoDestructiveStatement(): void {
        [$command, $db] = $this->makeCommand(['oc_learning_courses', 'oc_learning_questions']);

        $exit = $command->run(new StubConsoleInput([]), new CapturingOutput());

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertSame([], $this->destructiveStatements($db), 'dry run must not modify anything');
    }

    public function testExecuteDeletesMetadataBeforeDroppingTables(): void {
        [$command, $db] = $this->makeCommand(['oc_learning_courses', 'oc_learning_cert_keys'], certRows: 3);

        $exit = $command->run(new StubConsoleInput(['--execute' => true, '--drop-certificates' => true]), new CapturingOutput());

        $this->assertSame(Command::SUCCESS, $exit);

        $sql = $this->destructiveStatements($db);
        $firstDrop = null;
        $lastDelete = null;
        foreach ($sql as $i => $statement) {
            if (stripos($statement, 'DROP') === 0 && $firstDrop === null) {
                $firstDrop = $i;
            }
            if (stripos($statement, 'DELETE') === 0) {
                $lastDelete = $i;
            }
        }

        $this->assertNotNull($firstDrop, 'tables must be dropped');
        $this->assertNotNull($lastDelete, 'metadata must be deleted');
        $this->assertLessThan(
            $firstDrop,
            $lastDelete,
            'oc_migrations must be cleared before tables are dropped, or a failed run leaves an unreinstallable state'
        );

        $dropped = array_filter($sql, static fn (string $s) => stripos($s, 'DROP') === 0);
        $this->assertCount(1, $dropped, 'one DROP naming every table — see dropTables()');
        $this->assertStringContainsString('oc_learning_cert_keys', implode(' ', $dropped));
        $this->assertStringContainsString('oc_learning_courses', implode(' ', $dropped));
    }

    public function testExecuteRefusesToDestroyIssuerKeyWithoutAnExplicitChoice(): void {
        [$command, $db] = $this->makeCommand(['oc_learning_courses', 'oc_learning_cert_keys'], certRows: 3);

        $exit = $command->run(new StubConsoleInput(['--execute' => true]), $output = new CapturingOutput());

        $this->assertSame(Command::INVALID, $exit);
        $this->assertSame([], $this->destructiveStatements($db), 'nothing may be touched when the choice is missing');
        $this->assertStringContainsString('--keep-certificates', $output->buffer);
    }

    public function testExecuteProceedsWhenNoCertificatesWereEverIssued(): void {
        [$command, $db] = $this->makeCommand(['oc_learning_courses', 'oc_learning_cert_keys'], certRows: 0);

        $exit = $command->run(new StubConsoleInput(['--execute' => true]), new CapturingOutput());

        $this->assertSame(Command::SUCCESS, $exit, 'an empty cert table is not a reason to make the admin choose');
        $this->assertNotSame([], $this->destructiveStatements($db));
    }

    public function testKeepCertificatesLeavesCertTablesAlone(): void {
        [$command, $db] = $this->makeCommand(['oc_learning_courses', 'oc_learning_cert_keys'], certRows: 3);

        $exit = $command->run(new StubConsoleInput(['--execute' => true, '--keep-certificates' => true]), new CapturingOutput());

        $this->assertSame(Command::SUCCESS, $exit);
        $dropped = implode(' ', array_filter($this->destructiveStatements($db), static fn (string $s) => stripos($s, 'DROP') === 0));
        $this->assertStringContainsString('oc_learning_courses', $dropped);
        $this->assertStringNotContainsString('oc_learning_cert_keys', $dropped);
    }

    /**
     * MySQL/MariaDB reads "..." as a string literal, not an identifier — Nextcloud does not set
     * ANSI_QUOTES. Emitting ANSI quotes there turns every statement into a syntax error, which the
     * catch-blocks would render as "0 rows" and "absent": a broken query looking like a clean instance.
     */
    public function testQuotesIdentifiersForTheActualDatabasePlatform(): void {
        [$command, $db] = $this->makeCommand(['oc_learning_courses'], dbType: 'mysql');

        $command->run(new StubConsoleInput(['--execute' => true]), new CapturingOutput());

        $sql = implode(' ', array_merge(
            array_column($db->executedStatements, 'sql'),
            array_column($db->executedQueries, 'sql')
        ));

        $this->assertStringNotContainsString('"', $sql, 'ANSI quotes are string literals on MySQL/MariaDB');
        $this->assertStringContainsString('`oc_learning_courses`', $sql);
        $this->assertStringContainsString('`oc_migrations`', $sql);
    }

    public function testQuotesIdentifiersWithAnsiQuotesOnPostgres(): void {
        [$command, $db] = $this->makeCommand(['oc_learning_courses'], dbType: 'pgsql');

        $command->run(new StubConsoleInput(['--execute' => true]), new CapturingOutput());

        $sql = implode(' ', array_column($db->executedStatements, 'sql'));

        $this->assertStringNotContainsString('`', $sql, 'backticks are not valid on PostgreSQL');
        $this->assertStringContainsString('"oc_learning_courses"', $sql);
    }

    /**
     * @param list<string> $missingTables
     */
    private function makePartialCommand(array $existingTables, array $missingTables): array {
        $results = [FakeResult::fromFetchAll(array_map(static fn ($t) => ['table_name' => $t], $existingTables))];
        foreach (range(1, count($existingTables) + 7) as $ignored) {
            $results[] = FakeResult::fromFetchOne(0);
        }
        $db = new PartialDbConnection($missingTables, $results);

        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValue')->willReturnCallback(
            static fn (string $key, $default = '') => match ($key) {
                'dbtableprefix' => 'oc_',
                'dbtype' => 'pgsql',
                default => $default,
            }
        );

        return [new UninstallCommand($db, $config), $db];
    }

    public function testReportsAMissingMetadataTableAsAbsentRatherThanEmpty(): void {
        // No activity app installed — the common case the devcloud dry run could not exercise.
        [$command, $db] = $this->makePartialCommand(['oc_learning_courses'], ['oc_activity']);

        $command->run(new StubConsoleInput([]), $output = new CapturingOutput());

        $this->assertMatchesRegularExpression('/oc_activity \(activity stream\)\s+absent/', $output->buffer);
        $this->assertMatchesRegularExpression('/oc_migrations \(migration bookkeeping\)\s+0 rows/', $output->buffer);
    }

    public function testSkipsAMissingMetadataTableAndStillDropsTheRest(): void {
        [$command, $db] = $this->makePartialCommand(['oc_learning_courses'], ['oc_activity']);

        $exit = $command->run(new StubConsoleInput(['--execute' => true]), new CapturingOutput());

        $this->assertSame(Command::SUCCESS, $exit);

        $sql = array_column($db->executedStatements, 'sql');
        $this->assertNotEmpty(array_filter($sql, static fn (string $s) => str_contains($s, 'oc_migrations')));
        $this->assertSame([], array_values(array_filter($sql, static fn (string $s) => str_contains($s, 'oc_activity'))));
        $this->assertNotEmpty(array_filter($sql, static fn (string $s) => stripos($s, 'DROP') === 0));
    }

    /**
     * The failure this whole command is ordered to prevent: tables gone, oc_migrations rows left
     * behind, and the operator told it succeeded.
     *
     * oc_activity_mq is the LAST scope, and it is absent on every instance without the activity
     * app. Probing it from inside the transaction aborts the block after the final DELETE — so
     * nothing raises, COMMIT quietly discards all seven deletes, and the run goes on to drop
     * every table. Existence must therefore be established before the transaction opens.
     */
    public function testMissingLastScopeDoesNotSilentlyDiscardTheMetadataDeletes(): void {
        $results = [FakeResult::fromFetchAll([['table_name' => 'oc_learning_courses']])];
        foreach (range(1, 8) as $ignored) {
            $results[] = FakeResult::fromFetchOne(0);
        }
        $db = new PostgresLikeDbConnection(['oc_activity_mq'], $results);

        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValue')->willReturnCallback(
            static fn (string $key, $default = '') => match ($key) {
                'dbtableprefix' => 'oc_',
                'dbtype' => 'pgsql',
                default => $default,
            }
        );

        $command = new UninstallCommand($db, $config);
        $exit = $command->run(new StubConsoleInput(['--execute' => true]), new CapturingOutput());

        $this->assertFalse($db->silentlyRolledBack, 'the transaction must never be poisoned from inside');
        $this->assertNotEmpty(
            array_filter($db->effectiveStatements, static fn (string $s) => str_contains($s, 'oc_migrations')),
            'the oc_migrations delete must actually commit — otherwise a reinstall boots broken'
        );
        $this->assertSame(Command::SUCCESS, $exit);
    }

    /**
     * @param list<string> $missingTables
     * @param list<string> $brokenTables
     */
    private function makeBrokenCommand(array $existingTables, array $missingTables, array $brokenTables): array {
        $results = [FakeResult::fromFetchAll(array_map(static fn ($t) => ['table_name' => $t], $existingTables))];
        foreach (range(1, count($existingTables) + 8) as $ignored) {
            $results[] = FakeResult::fromFetchOne(1);
        }
        $db = new PartialDbConnection($missingTables, $results, $brokenTables);

        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValue')->willReturnCallback(
            static fn (string $key, $default = '') => match ($key) {
                'dbtableprefix' => 'oc_',
                'dbtype' => 'pgsql',
                default => $default,
            }
        );

        return [new UninstallCommand($db, $config), $db];
    }

    /**
     * A count that fails for any reason other than "no such table" must not read as absent.
     *
     * If the oc_migrations count fails on a permission or connection error, treating it as absent
     * skips its delete — and the run still drops 54 tables. Tables gone, migration rows intact:
     * the state a reinstall cannot recover from, reported as success.
     */
    public function testAbortsWhenAMandatoryScopeCannotBeRead(): void {
        [$command, $db] = $this->makeBrokenCommand(['oc_learning_courses'], [], ['oc_migrations']);

        $exit = $command->run(new StubConsoleInput(['--execute' => true]), $output = new CapturingOutput());

        $this->assertNotSame(Command::SUCCESS, $exit, 'an unreadable oc_migrations must not report success');
        $this->assertSame([], $this->destructiveStatements($db), 'nothing may be dropped when the plan is not trustworthy');
        $this->assertStringContainsString('oc_migrations', $output->buffer);
    }

    /**
     * countRows() returning 0 on failure would silently disarm the certificate gate: an
     * unreadable cert_keys table reads as "no certificates", --execute proceeds without a
     * decision, and the irreversible issuer key is dropped anyway.
     */
    public function testAbortsWhenTheCertificateCountCannotBeRead(): void {
        [$command, $db] = $this->makeBrokenCommand(
            ['oc_learning_courses', 'oc_learning_cert_keys'],
            [],
            ['oc_learning_cert_keys']
        );

        $exit = $command->run(new StubConsoleInput(['--execute' => true]), new CapturingOutput());

        $this->assertNotSame(Command::SUCCESS, $exit);
        $this->assertSame([], $this->destructiveStatements($db), 'the issuer key must never be dropped on a guess');
    }

    /**
     * The tables reference each other (questions→pools, user_answers→sessions, …). Dropping them
     * one at a time in alphabetical order fails on every parent that still has children — proven
     * against MariaDB: pools, questions and sessions all refuse. One statement naming them all
     * resolves the internal keys; RESTRICT then still refuses if something OUTSIDE the app
     * depends on them, instead of CASCADE silently deleting a foreign view.
     */
    public function testDropsEveryTableInOneStatementWithRestrict(): void {
        [$command, $db] = $this->makeCommand(['oc_learning_courses', 'oc_learning_pools', 'oc_learning_questions']);

        $command->run(new StubConsoleInput(['--execute' => true]), new CapturingOutput());

        $drops = array_values(array_filter(
            array_column($db->executedStatements, 'sql'),
            static fn (string $s) => stripos($s, 'DROP TABLE') === 0
        ));

        $this->assertCount(1, $drops, 'one DROP naming all tables, not one per table');
        foreach (['oc_learning_courses', 'oc_learning_pools', 'oc_learning_questions'] as $t) {
            $this->assertStringContainsString($t, $drops[0]);
        }
        $this->assertStringContainsString('RESTRICT', $drops[0]);
        $this->assertStringNotContainsString('CASCADE', $drops[0], 'CASCADE would destroy foreign views');
    }

    public function testTogglesForeignKeyChecksAroundTheDropOnMysql(): void {
        [$command, $db] = $this->makeCommand(['oc_learning_courses', 'oc_learning_pools'], dbType: 'mysql');

        $command->run(new StubConsoleInput(['--execute' => true]), new CapturingOutput());

        $sql = array_column($db->executedStatements, 'sql');
        $off = array_search('SET FOREIGN_KEY_CHECKS = 0', $sql, true);
        $drop = null;
        $on = null;
        foreach ($sql as $i => $s) {
            if (stripos($s, 'DROP TABLE') === 0) {
                $drop = $i;
            }
            if ($s === 'SET FOREIGN_KEY_CHECKS = 1') {
                $on = $i;
            }
        }

        $this->assertNotFalse($off, 'MySQL refuses to drop a referenced parent otherwise');
        $this->assertNotNull($drop);
        $this->assertNotNull($on, 'the checks must be restored');
        $this->assertLessThan($drop, $off);
        $this->assertGreaterThan($drop, $on);
        $this->assertStringNotContainsString('RESTRICT', $sql[$drop], 'RESTRICT is not MySQL syntax here');
    }

    /**
     * The theming links must be REPORTED, not deleted.
     *
     * Application::boot() rewrites theming's privacyUrl/imprintUrl at this app's own routes on
     * every single request, and this command necessarily runs while the app is still enabled.
     * A DELETE here is undone by the next page load — and by `occ app:remove` itself, which
     * boots the app before disabling it. Deleting and announcing it would be a promise the
     * command cannot keep, so it prints the statement to run once the app is gone instead.
     */
    public function testReportsTheThemingLinksWithoutDeletingThemWhileTheAppCanRestoreThem(): void {
        [$command, $db] = $this->makeCommand(['oc_learning_courses']);

        $command->run(new StubConsoleInput(['--execute' => true]), $output = new CapturingOutput());

        $touchedTheming = array_values(array_filter(
            $db->executedStatements,
            static fn (array $s) => in_array('theming', $s['params'], true)
        ));
        $this->assertSame([], $touchedTheming, 'deleting these is undone by the next boot()');

        $this->assertStringContainsString('privacyUrl', $output->buffer);
        $this->assertStringContainsString('AFTER app:remove', $output->buffer, 'must say when the statement is safe to run');
        $this->assertStringContainsString('DELETE FROM oc_appconfig', $output->buffer, 'hand the admin the statement, not homework');
    }

    /**
     * The Dashboard layout row belongs to the dashboard app and holds the user's other widgets
     * too. Deleting it would remove somebody's whole dashboard to clean up one token, so the
     * command reports it and leaves it alone.
     */
    public function testReportsButDoesNotTouchForeignRowsItCannotSafelyEdit(): void {
        [$command, $db] = $this->makeCommand(['oc_learning_courses']);

        $command->run(new StubConsoleInput([]), $output = new CapturingOutput());

        $this->assertStringContainsString('dashboard', $output->buffer);
        $this->assertSame([], array_values(array_filter(
            $db->executedStatements,
            static fn (array $s) => str_contains($s['sql'], 'dashboard')
        )));
    }

    /**
     * The app is still enabled while this command runs — Nextcloud only loads an app's commands
     * for enabled apps, and in maintenance mode it loads none but app_api's, so there is no way
     * to run this with the instance quiesced. A request or cron job that was already in flight
     * can therefore write a notification, job or activity row after the deletes committed.
     *
     * So the command counts the scopes again after dropping the tables and clears whatever
     * reappeared. It cannot close the window completely — a write landing after the recheck is
     * still possible — but it turns "orphans for as long as the run took" into "orphans from the
     * last few milliseconds", and it says so rather than reporting a clean sweep.
     */
    public function testClearsMetadataThatReappearedWhileItWasRunning(): void {
        $results = [FakeResult::fromFetchAll([['table_name' => 'oc_learning_courses']])];
        $results[] = FakeResult::fromFetchOne(0);          // table row count
        foreach (range(1, 7) as $ignored) {                // metadata scopes, first pass
            $results[] = FakeResult::fromFetchOne(0);
        }
        $results[] = FakeResult::fromFetchOne(0);          // foreign appconfig
        $results[] = FakeResult::fromFetchOne(3);          // recheck: something wrote back
        foreach (range(1, 6) as $ignored) {
            $results[] = FakeResult::fromFetchOne(0);
        }
        $db = new FakeDbConnection([], $results);

        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValue')->willReturnCallback(
            static fn (string $key, $default = '') => match ($key) {
                'dbtableprefix' => 'oc_',
                'dbtype' => 'pgsql',
                default => $default,
            }
        );

        $command = new UninstallCommand($db, $config);
        $exit = $command->run(new StubConsoleInput(['--execute' => true]), $output = new CapturingOutput());

        $this->assertSame(Command::SUCCESS, $exit);

        $deletes = array_values(array_filter(
            array_column($db->executedStatements, 'sql'),
            static fn (string $s) => stripos($s, 'DELETE') === 0 && str_contains($s, 'oc_migrations')
        ));
        $this->assertCount(2, $deletes, 'the reappeared rows must be cleared in a second pass');
        $this->assertStringContainsString('reappeared', $output->buffer);
    }

    public function testAppJobsCoversEveryBackgroundJobClass(): void {
        $files = glob(__DIR__ . '/../../../lib/BackgroundJob/*.php') ?: [];
        $this->assertNotEmpty($files);

        $expected = array_map(
            static fn (string $f) => 'OCA\Learning\BackgroundJob\\' . basename($f, '.php'),
            $files
        );

        $missing = array_values(array_diff($expected, UninstallCommand::APP_JOBS));

        $this->assertSame([], $missing, 'APP_JOBS is missing job classes: ' . implode(', ', $missing));
    }
}

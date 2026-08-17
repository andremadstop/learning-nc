<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Command;

use OCA\Learning\Command\UninstallCommand;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\StubConsoleInput;

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
        // Row counts for the metadata scopes.
        foreach (range(1, 7) as $ignored) {
            $results[] = FakeResult::fromFetchOne(0);
        }
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

    private function destructiveStatements(FakeDbConnection $db): array {
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
        $this->assertCount(2, $dropped);
        $this->assertStringContainsString('oc_learning_cert_keys', implode(' ', $dropped));
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

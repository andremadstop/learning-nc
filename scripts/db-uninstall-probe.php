<?php
/**
 * Runs the real UninstallCommand against a real MariaDB or PostgreSQL.
 *
 * WHY THIS EXISTS
 *
 * Two classes of bug live here that no unit test and no dev-instance run can reach, and both
 * already bit once:
 *
 *   MariaDB — ANSI double quotes are string literals there, not identifiers, so every statement
 *   was a syntax error. Because the command swallows query errors to stay usable on partial
 *   installs, that surfaced as "0 rows" and "absent": a broken run looking exactly like a clean
 *   instance. A unit test can assert the emitted SQL; only a real MariaDB asserts it parses.
 *
 *   PostgreSQL — a failed statement aborts the whole transaction block (SQLSTATE 25P02), and
 *   COMMIT then succeeds while acting as a rollback. A swallowed error inside the transaction
 *   discards every metadata delete without raising anything, after which the run drops the
 *   tables anyway: the unreinstallable state, reported as success. Asserting the DELETE was
 *   *sent* does not catch that — only asserting the rows are gone afterwards does.
 *
 * The fixture deliberately leaves four scope tables absent, which is the shape of any instance
 * without the activity app, and it exercises the destructive path end to end — something that
 * must never be run against the live instance.
 *
 * HOW TO RUN
 *
 *   HOST=relais NET=devcloud_internal APP=devcloud-app
 *
 *   # MariaDB
 *   ssh $HOST 'docker run -d --name learning-mysqltest \
 *       -e MARIADB_ROOT_PASSWORD=testonly_throwaway -e MARIADB_DATABASE=nctest mariadb:10.11'
 *   # PostgreSQL
 *   ssh $HOST 'docker run -d --name learning-pgtest \
 *       -e POSTGRES_PASSWORD=testonly_throwaway -e POSTGRES_DB=nctest postgres:16-alpine'
 *
 *   # The throwaway container joins the existing network. Production containers are never
 *   # reconfigured — and a port published on 127.0.0.1 would not be reachable from inside them.
 *   ssh $HOST "docker network connect $NET learning-mysqltest"
 *
 *   scp scripts/db-uninstall-probe.php $HOST:/tmp/
 *   ssh $HOST "docker cp /tmp/db-uninstall-probe.php $APP:/tmp/ \
 *              && docker exec -e PROBE_PLATFORM=mysql $APP php /tmp/db-uninstall-probe.php"
 *   ssh $HOST "docker exec -e PROBE_PLATFORM=pgsql $APP php /tmp/db-uninstall-probe.php"
 *
 *   # afterwards, always:
 *   ssh $HOST "docker exec $APP rm -- /tmp/db-uninstall-probe.php; rm -- /tmp/db-uninstall-probe.php
 *              docker network disconnect $NET learning-mysqltest
 *              docker stop learning-mysqltest && docker rm learning-mysqltest"
 *
 * Exit 0 when every assertion passes, 1 otherwise. Run BOTH platforms whenever the command's SQL
 * or its transaction handling changes.
 *
 * The database is created and destroyed by this script. Point it at a throwaway container only —
 * it drops every table it touches.
 */
declare(strict_types=1);

$appPath = getenv('LEARNING_APP_PATH') ?: '/var/www/html/custom_apps/learning';
$platform = getenv('PROBE_PLATFORM') ?: 'mysql';          // mysql | pgsql
$dbHost = getenv('PROBE_DB_HOST') ?: ($platform === 'pgsql' ? 'learning-pgtest' : 'learning-mysqltest');
$dbName = getenv('PROBE_DB_NAME') ?: 'nctest';
$dbUser = getenv('PROBE_DB_USER') ?: ($platform === 'pgsql' ? 'postgres' : 'root');
$dbPass = getenv('PROBE_DB_PASS') ?: 'testonly_throwaway';

require_once $appPath . '/tests/bootstrap.php';

$dsn = $platform === 'pgsql'
    ? "pgsql:host={$dbHost};dbname={$dbName}"
    : "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
$pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

echo "=== platform: {$platform} ===\n";

/** Identifier quoting for the fixture DDL — the command's own quoting is what we are testing. */
$q = static fn (string $ident): string => $platform === 'pgsql' ? '"' . $ident . '"' : '`' . $ident . '`';
$autoPk = $platform === 'pgsql' ? 'id SERIAL PRIMARY KEY' : 'id INT PRIMARY KEY AUTO_INCREMENT';
$catalogueSql = $platform === 'pgsql'
    ? "SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_name LIKE 'oc\\_learning\\_%'"
    : "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'oc\\_learning\\_%'";

final class PdoResult {
    public function __construct(private PDOStatement $stmt) {}
    public function fetchAll(): array {
        return $this->stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function fetchOne(): mixed {
        $row = $this->stmt->fetch(PDO::FETCH_NUM);
        return $row === false ? false : $row[0];
    }
    public function fetch(): array|false {
        return $this->stmt->fetch(PDO::FETCH_ASSOC);
    }
    public function closeCursor(): void {
        $this->stmt->closeCursor();
    }
}

final class PdoConnection implements \OCP\IDBConnection {
    /** @var list<string> */
    public array $log = [];

    public function __construct(private PDO $pdo) {}

    public function getQueryBuilder() {
        throw new RuntimeException('UninstallCommand uses raw SQL only');
    }
    public function executeQuery(string $sql, array $params = []) {
        $this->log[] = $sql;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return new PdoResult($stmt);
    }
    public function executeStatement(string $sql, array $params = []): int {
        $this->log[] = $sql;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }
    public function escapeLikeParameter(string $input): string {
        return addcslashes($input, '\\%_');
    }
    public function beginTransaction(): void {
        $this->pdo->beginTransaction();
    }
    public function commit(): void {
        $this->pdo->commit();
    }
    public function rollBack(): void {
        $this->pdo->rollBack();
    }
    public function inTransaction(): bool {
        return $this->pdo->inTransaction();
    }
}

final class StaticConfig implements \OCP\IConfig {
    public function __construct(private string $platform) {}

    public function getUserValue(string $userId, string $appName, string $key, string $default = '') {
        return $default;
    }
    public function getAppValue(string $appName, string $key, string $default = '') {
        return $default;
    }
    public function setAppValue(string $appName, string $key, string $value) {}

    public function getSystemValue(string $key, $default = '') {
        return match ($key) {
            'dbtableprefix' => 'oc_',
            'dbtype' => $this->platform,
            default => $default,
        };
    }
    public function setUserValue(string $userId, string $appName, string $key, string $value) {}
    public function getUserKeys(string $userId, string $appName): array {
        return [];
    }
}

final class BufferedOutput implements \Symfony\Component\Console\Output\OutputInterface {
    public string $buffer = '';
    public function writeln(string|array $messages, int $options = 0): void {
        $this->buffer .= (is_array($messages) ? implode("\n", $messages) : $messages) . "\n";
    }
    public function write(string|array $messages, bool $newline = false, int $options = 0): void {
        $this->buffer .= is_array($messages) ? implode("\n", $messages) : $messages;
    }
}

// ── fixture: a plausible slice of a real install ─────────────────────────────
$fixture = [
    'oc_learning_courses' => 3,
    'oc_learning_questions' => 12,
    'oc_learning_cert_keys' => 1,
    'oc_learning_certificates' => 2,
    'oc_learning_q_translations' => 4,   // a legacy name left behind by a half-finished upgrade
    'oc_learning_user_stats' => 0,
];
// Children first, so a re-run can clear the foreign keys created below.
if ($platform === 'mysql') {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
}
foreach (array_keys($fixture) as $table) {
    $pdo->exec('DROP TABLE IF EXISTS ' . $q($table) . ($platform === 'pgsql' ? ' CASCADE' : ''));
}
foreach (['oc_learning_user_answers', 'oc_learning_sessions', 'oc_learning_pools', 'oc_learningXcollide'] as $table) {
    $pdo->exec('DROP TABLE IF EXISTS ' . $q($table) . ($platform === 'pgsql' ? ' CASCADE' : ''));
}
if ($platform === 'mysql') {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

foreach ($fixture as $table => $rows) {
    $pdo->exec('CREATE TABLE ' . $q($table) . " ($autoPk, note VARCHAR(32))");
    for ($i = 0; $i < $rows; $i++) {
        $pdo->exec('INSERT INTO ' . $q($table) . " (note) VALUES ('x')");
    }
}

// REAL foreign keys, mirroring the shipped schema (sessions->pools, questions->pools,
// user_answers->sessions/questions). The first version of this probe used FK-free tables of
// id+note, and passed while the command's one-table-at-a-time drop was broken: MySQL refuses
// to drop a referenced parent, so pools, questions and sessions would all have survived.
$pdo->exec('CREATE TABLE ' . $q('oc_learning_pools') . " ($autoPk)");
$pdo->exec('CREATE TABLE ' . $q('oc_learning_sessions') . " ($autoPk, pool_id INT,
    FOREIGN KEY (pool_id) REFERENCES " . $q('oc_learning_pools') . '(id))');
$pdo->exec('ALTER TABLE ' . $q('oc_learning_questions') . ' ADD COLUMN pool_id INT');
$pdo->exec('ALTER TABLE ' . $q('oc_learning_questions') . ' ADD FOREIGN KEY (pool_id) REFERENCES ' . $q('oc_learning_pools') . '(id)');
$pdo->exec('CREATE TABLE ' . $q('oc_learning_user_answers') . " ($autoPk, session_id INT, question_id INT,
    FOREIGN KEY (session_id) REFERENCES " . $q('oc_learning_sessions') . '(id),
    FOREIGN KEY (question_id) REFERENCES ' . $q('oc_learning_questions') . '(id))');

// A neighbour that an unescaped LIKE pattern would match — must survive.
$pdo->exec('CREATE TABLE ' . $q('oc_learningXcollide') . " ($autoPk)");
// A table a FUTURE version created — this version must report it, not destroy it.
$pdo->exec('DROP TABLE IF EXISTS ' . $q('oc_learning_future_feature'));
$pdo->exec('CREATE TABLE ' . $q('oc_learning_future_feature') . " ($autoPk)");

$pdo->exec('DROP TABLE IF EXISTS ' . $q('oc_migrations'));
$pdo->exec('CREATE TABLE ' . $q('oc_migrations') . ' (app VARCHAR(255), version VARCHAR(255))');
foreach (['1', '2', '3'] as $v) {
    $pdo->exec('INSERT INTO ' . $q('oc_migrations') . " VALUES ('learning', '$v')");
}
$pdo->exec('INSERT INTO ' . $q('oc_migrations') . " VALUES ('files', '1')");   // a foreign app — must survive

$pdo->exec('DROP TABLE IF EXISTS ' . $q('oc_appconfig'));
$pdo->exec('CREATE TABLE ' . $q('oc_appconfig') . ' (appid VARCHAR(255), configkey VARCHAR(255), configvalue VARCHAR(255))');
$insCfg = $pdo->prepare('INSERT INTO ' . $q('oc_appconfig') . ' (appid, configkey, configvalue) VALUES (?, ?, ?)');
$insCfg->execute(['learning', 'enabled', 'yes']);
$insCfg->execute(['learning', 'ai_provider', 'gemini']);
$insCfg->execute(['theming', 'color', '#1a3a5c']);
// The legal links this app redirected at itself — must go.
$insCfg->execute(['theming', 'privacyUrl', 'https://example.org/apps/learning/privacy']);
// An imprint the admin set themselves — must NOT be touched.
$insCfg->execute(['theming', 'imprintUrl', 'https://example.org/legal/imprint']);

$pdo->exec('DROP TABLE IF EXISTS ' . $q('oc_jobs'));
$pdo->exec('CREATE TABLE ' . $q('oc_jobs') . " ($autoPk, class VARCHAR(255))");
// Bound, not interpolated: a backslash inside a SQL string literal is an escape on MySQL and a
// literal character on PostgreSQL, so an interpolated namespace lands differently on each engine
// and the fixture would silently not match what the command looks for.
$insertJob = $pdo->prepare('INSERT INTO ' . $q('oc_jobs') . ' (class) VALUES (?)');
$insertJob->execute(['OCA\Learning\BackgroundJob\SendRemindersJob']);
$insertJob->execute(['OCA\Files\BackgroundJob\ScanFiles']);

// oc_preferences / oc_notifications / oc_activity / oc_activity_mq deliberately absent —
// that is the shape of any instance without the activity app, and it exercises the
// "table does not exist" path on MariaDB rather than only on the fakes.

$db = new PdoConnection($pdo);
$command = new \OCA\Learning\Command\UninstallCommand($db, new StaticConfig($platform));

$failures = [];
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    if (!$ok) {
        $failures[] = $label;
    }
};

// ── 1. dry run ───────────────────────────────────────────────────────────────
echo "\n=== DRY RUN ===\n";
$out = new BufferedOutput();
$exit = $command->run(new \Symfony\Component\Console\Tester\StubConsoleInput([]), $out);
echo $out->buffer;

echo "\n=== ASSERTIONS: dry run ===\n";
$check('exit 0', $exit === 0);
$check('catalogue query finds the tables (information_schema + DATABASE() branch)', substr_count($out->buffer, 'oc_learning_') >= 6);
$check('row counts are real, not swallowed zeros (backtick quoting parses)', str_contains($out->buffer, '12 rows'));
$check('legacy table name discovered', str_contains($out->buffer, 'oc_learning_q_translations'));
$check('unknown table reported, not planned for removal', str_contains($out->buffer, 'oc_learning_future_feature'));
$check('oc_migrations counted as 3, not 4 — foreign app not matched', (bool)preg_match('/oc_migrations.*?3 rows/s', $out->buffer));
$check('absent tables reported as absent', substr_count($out->buffer, 'absent') >= 4);
$check('dry run changed nothing', (int)$pdo->query('SELECT COUNT(*) FROM ' . $q('oc_learning_courses'))->fetchColumn() === 3);

// ── 2. the certificate gate ──────────────────────────────────────────────────
echo "\n=== ASSERTIONS: certificate gate ===\n";
$out2 = new BufferedOutput();
$exit2 = $command->run(new \Symfony\Component\Console\Tester\StubConsoleInput(['--execute' => true]), $out2);
$check('refuses --execute without a certificate decision', $exit2 === 2);
$check('the refused run dropped nothing', (int)$pdo->query('SELECT COUNT(*) FROM ' . $q('oc_learning_courses'))->fetchColumn() === 3);

// ── 3. the destructive path ──────────────────────────────────────────────────
echo "\n=== EXECUTE ===\n";
$out3 = new BufferedOutput();
$exit3 = $command->run(
    new \Symfony\Component\Console\Tester\StubConsoleInput(['--execute' => true, '--drop-certificates' => true]),
    $out3
);
echo $out3->buffer;

echo "\n=== ASSERTIONS: execute ===\n";
$check('exit 0', $exit3 === 0);

$remaining = $pdo->query($catalogueSql)->fetchAll(PDO::FETCH_COLUMN);
$check(
    'every known learning table is gone, foreign keys and all',
    array_values(array_diff($remaining, ['oc_learning_future_feature', 'oc_learningXcollide'])) === []
);
$allTables = $pdo->query(
    $platform === 'pgsql'
        ? "SELECT tablename FROM pg_tables WHERE schemaname = current_schema()"
        : 'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()'
)->fetchAll(PDO::FETCH_COLUMN);
$check('the LIKE-collision neighbour survived', in_array('oc_learningXcollide', $allTables, true));
$check('the unknown table survived', in_array('oc_learning_future_feature', $remaining, true));
$check('learning migration rows gone', (int)$pdo->query('SELECT COUNT(*) FROM ' . $q('oc_migrations') . " WHERE app='learning'")->fetchColumn() === 0);
$check('foreign migration rows intact', (int)$pdo->query('SELECT COUNT(*) FROM ' . $q('oc_migrations') . " WHERE app='files'")->fetchColumn() === 1);
$check('learning appconfig gone', (int)$pdo->query('SELECT COUNT(*) FROM ' . $q('oc_appconfig') . " WHERE appid='learning'")->fetchColumn() === 0);
// (replaced by the three targeted theming checks below)
$check('learning job gone', (int)$pdo->query('SELECT COUNT(*) FROM ' . $q('oc_jobs') . " WHERE class LIKE '%Learning%'")->fetchColumn() === 0);
$check(
    'theming link pointing at this app removed',
    (int)$pdo->query('SELECT COUNT(*) FROM ' . $q('oc_appconfig') . " WHERE appid='theming' AND configkey='privacyUrl'")->fetchColumn() === 0
);
$check(
    "the admin's own imprint URL left alone",
    (int)$pdo->query('SELECT COUNT(*) FROM ' . $q('oc_appconfig') . " WHERE appid='theming' AND configkey='imprintUrl'")->fetchColumn() === 1
);
$check(
    'unrelated theming settings intact',
    (int)$pdo->query('SELECT COUNT(*) FROM ' . $q('oc_appconfig') . " WHERE appid='theming' AND configkey='color'")->fetchColumn() === 1
);
$check('foreign job intact', (int)$pdo->query('SELECT COUNT(*) FROM ' . $q('oc_jobs') . " WHERE class LIKE '%Files%'")->fetchColumn() === 1);
$check(
    $platform === 'pgsql'
        ? 'no backtick identifier was ever sent to PostgreSQL'
        : 'no ANSI-quoted identifier was ever sent to MariaDB',
    $platform === 'pgsql' ? !preg_grep('/`oc_/', $db->log) : !preg_grep('/"oc_/', $db->log)
);

// The poisoned-transaction trap: four scope tables are absent above, and on PostgreSQL a probe
// that fails inside the transaction turns COMMIT into a silent ROLLBACK. Asserting the DELETE was
// *sent* would not catch that — only that the rows are actually gone does.
$check(
    'metadata deletes really committed despite four absent scope tables',
    (int)$pdo->query('SELECT COUNT(*) FROM ' . $q('oc_migrations') . " WHERE app='learning'")->fetchColumn() === 0
);

echo "\n" . ($failures === [] ? "ALL PASS\n" : 'FAILURES: ' . implode(' | ', $failures) . "\n");
exit($failures === [] ? 0 : 1);

<?php
/**
 * Runs the real UninstallCommand against a real MariaDB.
 *
 * WHY THIS EXISTS
 *
 * The dev instance is PostgreSQL, so a whole branch of UninstallCommand is unreachable there:
 * the information_schema + DATABASE() catalogue query, the TABLE_NAME casing, ESCAPE '!', and
 * backtick identifier quoting. That last one already broke once — ANSI double quotes are string
 * literals on MariaDB, and because the command swallows query errors to stay usable on partial
 * installs, the failure surfaced as "0 rows" and "absent" rather than as an error: a broken run
 * looking exactly like a clean instance. The unit suite can assert the emitted SQL; only a real
 * MariaDB can assert it parses and runs.
 *
 * It also exercises the destructive path end to end, which must never be run against the live
 * instance.
 *
 * HOW TO RUN
 *
 *   HOST=relais NET=devcloud_internal APP=devcloud-app
 *
 *   ssh $HOST 'docker run -d --name learning-mysqltest \
 *       -e MARIADB_ROOT_PASSWORD=testonly_throwaway -e MARIADB_DATABASE=nctest mariadb:10.11'
 *   ssh $HOST "docker network connect $NET learning-mysqltest"      # the throwaway joins the net;
 *                                                                   # production containers are untouched
 *   scp scripts/mariadb-uninstall-probe.php $HOST:/tmp/
 *   ssh $HOST "docker cp /tmp/mariadb-uninstall-probe.php $APP:/tmp/ && docker exec $APP php /tmp/mariadb-uninstall-probe.php"
 *
 *   # afterwards, always:
 *   ssh $HOST "docker exec $APP rm -- /tmp/mariadb-uninstall-probe.php; rm -- /tmp/mariadb-uninstall-probe.php
 *              docker network disconnect $NET learning-mysqltest
 *              docker stop learning-mysqltest && docker rm learning-mysqltest"
 *
 * Exit 0 when every assertion passes, 1 otherwise. Run it whenever UninstallCommand's SQL changes.
 *
 * The database is created and destroyed by this script. Point it at a throwaway container only —
 * it drops every table it touches.
 */
declare(strict_types=1);

$appPath = getenv('LEARNING_APP_PATH') ?: '/var/www/html/custom_apps/learning';
$dbHost = getenv('PROBE_DB_HOST') ?: 'learning-mysqltest';
$dbName = getenv('PROBE_DB_NAME') ?: 'nctest';
$dbUser = getenv('PROBE_DB_USER') ?: 'root';
$dbPass = getenv('PROBE_DB_PASS') ?: 'testonly_throwaway';

require_once $appPath . '/tests/bootstrap.php';

$pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

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
            'dbtype' => 'mysql',
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
foreach ($fixture as $table => $rows) {
    $pdo->exec("DROP TABLE IF EXISTS `$table`");
    $pdo->exec("CREATE TABLE `$table` (id INT PRIMARY KEY AUTO_INCREMENT, note VARCHAR(32))");
    for ($i = 0; $i < $rows; $i++) {
        $pdo->exec("INSERT INTO `$table` (note) VALUES ('x')");
    }
}
// A table a FUTURE version created — this version must report it, not destroy it.
$pdo->exec('DROP TABLE IF EXISTS `oc_learning_future_feature`');
$pdo->exec('CREATE TABLE `oc_learning_future_feature` (id INT PRIMARY KEY AUTO_INCREMENT)');

$pdo->exec('DROP TABLE IF EXISTS `oc_migrations`');
$pdo->exec('CREATE TABLE `oc_migrations` (app VARCHAR(255), version VARCHAR(255))');
foreach (['1', '2', '3'] as $v) {
    $pdo->exec("INSERT INTO `oc_migrations` VALUES ('learning', '$v')");
}
$pdo->exec("INSERT INTO `oc_migrations` VALUES ('files', '1')");   // a foreign app — must survive

$pdo->exec('DROP TABLE IF EXISTS `oc_appconfig`');
$pdo->exec('CREATE TABLE `oc_appconfig` (appid VARCHAR(255), configkey VARCHAR(255))');
$pdo->exec("INSERT INTO `oc_appconfig` VALUES ('learning', 'enabled'), ('learning', 'ai_provider'), ('theming', 'color')");

$pdo->exec('DROP TABLE IF EXISTS `oc_jobs`');
$pdo->exec('CREATE TABLE `oc_jobs` (id INT PRIMARY KEY AUTO_INCREMENT, class VARCHAR(255))');
$pdo->exec("INSERT INTO `oc_jobs` (class) VALUES ('OCA\\\\Learning\\\\BackgroundJob\\\\SendRemindersJob'), ('OCA\\\\Files\\\\BackgroundJob\\\\ScanFiles')");

// oc_preferences / oc_notifications / oc_activity / oc_activity_mq deliberately absent —
// that is the shape of any instance without the activity app, and it exercises the
// "table does not exist" path on MariaDB rather than only on the fakes.

$db = new PdoConnection($pdo);
$command = new \OCA\Learning\Command\UninstallCommand($db, new StaticConfig());

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
$check('dry run changed nothing', (int)$pdo->query('SELECT COUNT(*) FROM `oc_learning_courses`')->fetchColumn() === 3);

// ── 2. the certificate gate ──────────────────────────────────────────────────
echo "\n=== ASSERTIONS: certificate gate ===\n";
$out2 = new BufferedOutput();
$exit2 = $command->run(new \Symfony\Component\Console\Tester\StubConsoleInput(['--execute' => true]), $out2);
$check('refuses --execute without a certificate decision', $exit2 === 2);
$check('the refused run dropped nothing', (int)$pdo->query('SELECT COUNT(*) FROM `oc_learning_courses`')->fetchColumn() === 3);

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

$remaining = $pdo
    ->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'oc\\_learning\\_%'")
    ->fetchAll(PDO::FETCH_COLUMN);
$check('every known learning table is gone', $remaining === ['oc_learning_future_feature']);
$check('the unknown table survived', in_array('oc_learning_future_feature', $remaining, true));
$check('learning migration rows gone', (int)$pdo->query("SELECT COUNT(*) FROM `oc_migrations` WHERE app='learning'")->fetchColumn() === 0);
$check('foreign migration rows intact', (int)$pdo->query("SELECT COUNT(*) FROM `oc_migrations` WHERE app='files'")->fetchColumn() === 1);
$check('learning appconfig gone', (int)$pdo->query("SELECT COUNT(*) FROM `oc_appconfig` WHERE appid='learning'")->fetchColumn() === 0);
$check('foreign appconfig intact', (int)$pdo->query("SELECT COUNT(*) FROM `oc_appconfig` WHERE appid='theming'")->fetchColumn() === 1);
$check('learning job gone', (int)$pdo->query("SELECT COUNT(*) FROM `oc_jobs` WHERE class LIKE '%Learning%'")->fetchColumn() === 0);
$check('foreign job intact', (int)$pdo->query("SELECT COUNT(*) FROM `oc_jobs` WHERE class LIKE '%Files%'")->fetchColumn() === 1);
$check('no ANSI-quoted identifier was ever sent to MariaDB', !preg_grep('/"oc_/', $db->log));

echo "\n" . ($failures === [] ? "ALL PASS\n" : 'FAILURES: ' . implode(' | ', $failures) . "\n");
exit($failures === [] ? 0 : 1);

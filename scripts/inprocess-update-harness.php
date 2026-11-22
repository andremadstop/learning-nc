<?php
declare(strict_types=1);

/**
 * In-process app update harness — run by scripts/inprocess-update-probe.sh inside a throwaway
 * Nextcloud container, as www-data, from the Nextcloud root:
 *
 *   php /tmp/inprocess-update-harness.php <new-app-dir>
 *
 * It reproduces what `occ app:update learning` (and an update from the app management page) does:
 * the process boots with the INSTALLED app version, loads the console commands the way console.php
 * does (that constructs the app's commands and the services they inject, so those classes are now
 * defined from the old files), then the app directory is replaced with the new version and
 * AppManager::upgradeApp() runs the migrations and repair steps — in the same process, as
 * Installer::updateAppstoreApp() does after downloadApp(). A migration that reads an app class or
 * constant which changed in the new release therefore sees the OLD definition here, exactly as on
 * a real instance (5.18.0: Version011600 read TranslationService::ALLOWED_LANGS, private in 5.17.0).
 *
 * A fresh `occ upgrade` after copying the files (boot gate, lifecycle probe) cannot see this.
 * Prints PROBE lines; exit 0 only when upgradeApp() returned true without throwing.
 */

use OCP\Server;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

if (PHP_SAPI !== 'cli' || $argc !== 2) {
    fwrite(STDERR, "usage: php inprocess-update-harness.php <new-app-dir>\n");
    exit(2);
}
$newDir = rtrim($argv[1], '/');
if (!is_file($newDir . '/appinfo/info.xml')) {
    fwrite(STDERR, "no appinfo/info.xml in $newDir\n");
    exit(2);
}

require_once getcwd() . '/lib/base.php';
// base.php installs a handler that logs uncaught throwables and still exits 0 — catch everything here.
set_exception_handler(static function (\Throwable $e): void {
    echo 'PROBE harness=exception ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    exit(1);
});

// PHP >= 8.4: Nextcloud's DI container may hand out lazy ghosts, so a command's constructor
// dependencies are never resolved and their classes never load. PHP 8.2/8.3 (most instances, the
// home cloud runs 8.2) build them eagerly — that is how 5.17.0's TranslationService got into the
// process. Force the eager path so the probe sees what those instances see.
echo 'PROBE php=' . PHP_VERSION . ' lazy_objects_before=' . (\OC\AppFramework\Utility\SimpleContainer::$useLazyObjects ? 'on' : 'off') . "\n";
\OC\AppFramework\Utility\SimpleContainer::$useLazyObjects = false;

$application = Server::get(\OC\Console\Application::class);
$application->loadCommands(new ArgvInput(['occ', 'list']), new ConsoleOutput());

$appPath = Server::get(\OCP\App\IAppManager::class)->getAppPath('learning');
$before = Server::get(\OCP\IConfig::class)->getAppValue('learning', 'installed_version');
$loaded = array_values(array_filter(get_declared_classes(), static fn (string $c): bool => str_starts_with($c, 'OCA\\Learning\\')));
echo 'PROBE installed_before=' . $before . "\n";
echo 'PROBE app_classes_loaded_before_swap=' . count($loaded) . "\n";
echo 'PROBE app_classes=' . implode(',', array_map(static fn (string $c): string => substr($c, 13), $loaded)) . "\n";
echo 'PROBE translation_service_loaded=' . (in_array('OCA\\Learning\\Service\\TranslationService', $loaded, true) ? 'yes' : 'no') . "\n";

// Replace, not overlay — as the installer does after extracting the downloaded archive.
$cmd = 'rm -r -- ' . escapeshellarg($appPath) . ' && cp -a ' . escapeshellarg($newDir) . ' ' . escapeshellarg($appPath);
exec($cmd, $out, $rc);
if ($rc !== 0) {
    echo "PROBE swap=fail\n";
    exit(1);
}
echo "PROBE swap=ok\n";

try {
    $ok = Server::get(\OC\App\AppManager::class)->upgradeApp('learning');
} catch (\Throwable $e) {
    echo 'PROBE upgrade=exception ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    exit(1);
}
echo 'PROBE upgrade=' . ($ok ? 'ok' : 'false') . "\n";
echo 'PROBE installed_after=' . Server::get(\OCP\IConfig::class)->getAppValue('learning', 'installed_version') . "\n";
exit($ok ? 0 : 1);

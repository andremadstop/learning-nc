<?php
/**
 * Child process for ExamStartRaceTest: boots Nextcloud, reports READY, blocks until the test
 * writes GO to its stdin, then starts exactly one counting exam through the container-wired
 * TrainingService and prints "OK <session_id>" or "REFUSED limit".
 *
 * Usage: php exam_start_worker.php <uid> <pool_id> <course_id>
 *
 * Every way out after GO prints exactly one line (exit 0), or FAILED <class>: <message> (exit 1);
 * a process that ends without one prints NO-RESULT from the shutdown function.
 */
declare(strict_types=1);

$reported = false;
register_shutdown_function(static function () use (&$reported): void {
	if ($reported) {
		return;
	}
	$last = error_get_last();
	fwrite(STDOUT, 'NO-RESULT ' . ($last === null
		? 'no PHP error recorded'
		: $last['message'] . ' in ' . $last['file'] . ':' . $last['line']) . "\n");
	fflush(STDOUT);
});
$report = static function (string $line) use (&$reported): void {
	$reported = true;
	fwrite(STDOUT, $line . "\n");
	fflush(STDOUT);
};

require_once '/var/www/html/lib/base.php';

use OCA\Learning\Service\ExamAttemptLimitException;
use OCA\Learning\Service\TrainingService;

[$_, $uid, $poolId, $courseId] = $argv;
\OCP\Server::get(\OCP\App\IAppManager::class)->loadApp('learning');
$training = \OCP\Server::get(TrainingService::class);

fwrite(STDOUT, "READY\n");
fflush(STDOUT);
if (trim((string)fgets(STDIN)) !== 'GO') {
	$report('NO-GO');
	exit(2);
}

try {
	$session = $training->startSession((int)$poolId, $uid, null, 'exam', null, 'en', (int)$courseId);
	$report('OK ' . (int)($session['session_id'] ?? 0));
} catch (ExamAttemptLimitException $e) {
	$report('REFUSED limit');
} catch (\Throwable $e) {
	$report('FAILED ' . get_class($e) . ': ' . $e->getMessage());
	exit(1);
}
exit(0);

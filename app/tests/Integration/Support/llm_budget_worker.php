<?php
/**
 * Child process for LlmBudgetConcurrencyTest: boots Nextcloud, reports READY, blocks until the
 * test writes GO to its stdin, then makes exactly one reservation and prints OK or RATE_LIMIT.
 * The ticket is not released, so concurrency slots stay taken until the test cleans up.
 *
 * Usage: php llm_budget_worker.php <uid|-> <purpose>
 *
 * After GO every way out prints exactly one result line: OK, RATE_LIMIT or ERROR <category>
 * (exit 0), or FAILED <class>: <message> for any other throwable (exit 1). If the process ends
 * without one of these (a fatal error, or an exit() somewhere inside Nextcloud), the shutdown
 * function prints NO-RESULT with the last PHP error, so the test never counts an empty line as an
 * outcome (audit #120: 19 of 20 workers once ended with exit 0 and no output on MariaDB).
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

use OCA\Learning\Service\Llm\LlmBudget;
use OCA\Learning\Service\Llm\LlmProviderException;
use OCA\Learning\Service\Llm\LlmPurpose;

[$_, $uid, $purpose] = $argv;
\OCP\Server::get(\OCP\App\IAppManager::class)->loadApp('learning');
$budget = \OCP\Server::get(LlmBudget::class);
$purposeEnum = LlmPurpose::from($purpose);

fwrite(STDOUT, "READY\n");
fflush(STDOUT);
if (trim((string)fgets(STDIN)) !== 'GO') {
	$report('NO-GO');
	exit(2);
}

try {
	$budget->reserve($uid === '-' ? null : $uid, $purposeEnum);
	$report('OK');
} catch (LlmProviderException $e) {
	$report($e->category === 'rate_limit' ? 'RATE_LIMIT' : 'ERROR ' . $e->category);
} catch (\Throwable $e) {
	$report('FAILED ' . get_class($e) . ': ' . $e->getMessage());
	exit(1);
}
exit(0);
